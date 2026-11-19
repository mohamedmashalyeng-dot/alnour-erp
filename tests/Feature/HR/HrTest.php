<?php

namespace Tests\Feature\HR;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Core\Models\AuditLog;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\SalaryChange;
use App\Modules\HR\Services\HrService;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Services\PayrollService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class HrTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private HrService $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->hr = app(HrService::class);
    }

    private function employee(array $attrs = [], ?User $user = null): Employee
    {
        $current = auth()->user();
        $this->actingAsRole('admin', [$this->branch]);
        $e = app(PayrollService::class)->saveEmployee(array_merge(['branch_id' => $this->branch->id, 'user_id' => $user?->id, 'name' => 'Sara',
            'base_salary' => 300000, 'hired_on' => '2026-01-01', 'is_active' => true], $attrs));
        if ($current) {
            $this->actingAs($current);
        }

        return $e->refresh();
    }

    private function profile(array $over = []): array
    {
        return array_merge(['name' => 'Sara', 'employment_type' => 'full_time', 'employment_status' => 'active', 'annual_leave_days' => 21,
            'national_id' => '1234567890', 'address' => 'Riyadh', 'emergency_contact_phone' => '0500000000'], $over);
    }

    public function test_sensitive_fields_need_permission_are_encrypted_and_redacted_in_audit(): void
    {
        $e = $this->employee();

        $this->actingAsRole('branch-manager', [$this->branch]); // hr.manage, no hr.sensitive
        $this->hr->updateProfile($e, $this->profile(['job_title' => 'Optician']));
        $e->refresh();
        $this->assertSame('Optician', $e->job_title);
        $this->assertNull($e->national_id, 'Ignored without hr.sensitive');

        $this->actingAsRole('branch-manager', [$this->branch], ['hr.sensitive']);
        $this->hr->updateProfile($e, $this->profile());
        $e->refresh();
        $this->assertSame('1234567890', $e->national_id);
        $raw = DB::table('employees')->where('id', $e->id)->value('national_id');
        $this->assertStringNotContainsString('1234567890', $raw, 'Encrypted at rest');
        $this->assertSame('••••••7890', $e->maskedNationalId());

        $log = AuditLog::where('event', 'hr.employee.updated')->latest('id')->first();
        $this->assertSame('[redacted]', $log->new_values['national_id']);
        $this->assertStringNotContainsString('1234567890', json_encode(AuditLog::all()->toArray()));
        $this->assertArrayNotHasKey('national_id', $e->toArray(), 'Hidden from serialization');
    }

    public function test_branch_isolation_and_permissions(): void
    {
        $e = $this->employee();
        $other = Branch::factory()->create();

        $this->actingAsRole('branch-manager', [$other]);
        $this->expectException(AuthorizationException::class);
        $this->hr->updateProfile($e, $this->profile());
    }

    public function test_cashier_cannot_manage_hr_but_can_use_self_service(): void
    {
        $this->employee();
        $this->actingAsRole('cashier', [$this->branch]);
        $this->get(route('hr.employees'))->assertForbidden();
        $this->get(route('hr.attendance'))->assertForbidden();
        $this->get(route('hr.me'))->assertOk()->assertSee(__('Your user account is not linked to an active employee record.'));
    }

    public function test_salary_history_is_recorded_and_immutable(): void
    {
        $e = $this->employee();
        $this->actingAsRole('accountant', [$this->branch]);
        $this->hr->changeSalary($e, 350000, '2026-06-01', 'Annual raise');
        app(PayrollService::class)->saveEmployee(['branch_id' => $this->branch->id, 'name' => 'Sara', 'base_salary' => 360000, 'is_active' => true], $e->refresh());

        $history = SalaryChange::where('employee_id', $e->id)->orderBy('id')->get();
        $this->assertSame([[0, 300000], [300000, 350000], [350000, 360000]], $history->map(fn ($h) => [$h->old_salary, $h->new_salary])->all());
        $this->assertSame(360000, $e->refresh()->base_salary);

        $this->expectException(LogicException::class);
        $history->first()->update(['new_salary' => 1]);
    }

    public function test_branch_manager_cannot_change_salary(): void
    {
        $e = $this->employee();
        $this->actingAsRole('branch-manager', [$this->branch]);
        $this->expectException(AuthorizationException::class);
        $this->hr->changeSalary($e, 999999, today()->toDateString(), null);
    }

    public function test_attendance_lateness_self_check_in_and_validation(): void
    {
        $user = User::factory()->create();
        $e = $this->employee(['name' => 'Ali'], $user);
        $e->forceFill(['shift_start' => '09:00'])->save();

        $this->actingAsRole('branch-manager', [$this->branch]);
        $r = $this->hr->markAttendance($e, '2026-11-02', 'present', '09:25', '17:00');
        $this->assertSame('late', $r->status);
        $this->assertSame(25, $r->late_minutes);
        $this->assertSame(455, $r->worked_minutes);
        // Correction updates the same day (unique per employee/day) and is audited.
        $this->hr->markAttendance($e, '2026-11-02', 'present', '08:55', '17:00');
        $this->assertSame(1, AttendanceRecord::count());
        $this->assertSame('present', AttendanceRecord::sole()->status);
        $this->assertTrue(AuditLog::where('event', 'hr.attendance_corrected')->exists());

        try {
            $this->hr->markAttendance($e, today()->addDay()->toDateString(), 'present');
            $this->fail('Future attendance must be rejected');
        } catch (ValidationException) {
        }

        Carbon::setTestNow(today()->setTime(9, 10));
        $user->assignRole('cashier');
        $this->actingAs($user);
        $in = $this->hr->checkIn($user);
        $this->assertSame('late', $in->status);
        $this->assertSame('self', $in->source);
        try {
            $this->hr->checkIn($user);
            $this->fail('Second check-in must be rejected');
        } catch (ValidationException) {
        }
        Carbon::setTestNow(today()->setTime(17, 10));
        $this->assertSame(480, $this->hr->checkOut($user)->worked_minutes);
        Carbon::setTestNow();
    }

    public function test_leave_balance_overlap_and_segregation_of_duties(): void
    {
        $mgrUser = User::factory()->create();
        $staffUser = User::factory()->create();
        $e = $this->employee(['name' => 'Staff'], $staffUser);
        $mgrEmployee = $this->employee(['name' => 'Manager'], $mgrUser);

        $staffUser->assignRole('cashier');
        $staffUser->branches()->sync([$this->branch->id]);
        $this->actingAs($staffUser);
        $leave = $this->hr->requestLeave($e, 'annual', '2026-12-01', '2026-12-10', 'Family trip');
        $this->assertSame(10, $leave->days);
        $this->assertSame(11, $this->hr->annualBalance($e, '2026-12-01'));

        try {
            $this->hr->requestLeave($e, 'sick', '2026-12-05', '2026-12-06');
            $this->fail('Overlap must be rejected');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('start', $ex->errors());
        }
        try {
            $this->hr->requestLeave($e, 'annual', '2026-12-20', '2026-12-31');
            $this->fail('Balance must be enforced');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('days', $ex->errors());
        }
        // A cashier cannot approve.
        try {
            $this->hr->decideLeave($leave, true);
            $this->fail('Cashier cannot decide leave');
        } catch (AuthorizationException) {
        }

        // Manager cannot approve their own request.
        $mgrUser->assignRole('branch-manager');
        $mgrUser->branches()->sync([$this->branch->id]);
        $this->actingAs($mgrUser);
        app(BranchAccess::class)->flush();
        $own = $this->hr->requestLeave($mgrEmployee, 'annual', '2026-12-01', '2026-12-02');
        try {
            $this->hr->decideLeave($own, true);
            $this->fail('Self-approval must be rejected');
        } catch (ValidationException) {
        }

        $this->hr->decideLeave($leave, true, 'Enjoy');
        $this->assertSame('approved', $leave->refresh()->status);
        $this->hr->cancelLeave($leave);
        $this->assertSame('cancelled', $leave->refresh()->status);
        $this->assertSame(21, $this->hr->annualBalance($e, '2026-12-01'));
    }

    public function test_payroll_deducts_absences_and_unpaid_leave(): void
    {
        $user = User::factory()->create();
        $e = $this->employee(['base_salary' => 300000], $user);
        $this->actingAsRole('admin', [$this->branch]);
        $this->hr->markAttendance($e, '2026-09-01', 'absent');
        $this->hr->markAttendance($e, '2026-09-02', 'half_day', '09:00', '13:00');
        $leave = $this->hr->requestLeave($e, 'unpaid', '2026-09-28', '2026-10-03'); // 3 days fall in September
        $this->hr->decideLeave($leave, true);

        $summary = $this->hr->monthSummary($e, 2026, 9);
        $this->assertSame(1, $summary['absent']);
        $this->assertSame(1, $summary['half_days']);
        $this->assertSame(3, $summary['unpaid_leave']);

        $run = app(PayrollService::class)->createRun($this->branch->id, 2026, 9);
        $item = $run->items()->sole();
        // 300000 × (2×(1+3)+1) / (2×30) = 45000
        $this->assertSame(45000, $item->attendance_deduction);
        $this->assertSame(45000, $item->deductions);
        $this->assertSame(255000, $item->net_pay);
        $this->assertSame(1, $item->absent_days);
        $this->assertSame(3, $item->unpaid_leave_days);
    }

    public function test_hr_pages_render(): void
    {
        $user = User::factory()->create();
        $e = $this->employee([], $user);
        $this->actingAsRole('admin', [$this->branch]);
        $this->hr->markAttendance($e, today()->toDateString(), 'present', '09:00');
        $this->hr->addReview($e, ['period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'rating' => 4, 'strengths' => 'Customer care']);

        $this->get(route('hr.employees'))->assertOk()->assertSee('Sara');
        foreach (['profile', 'attendance', 'leave', 'salary', 'reviews'] as $tab) {
            $this->get(route('hr.employees.show', ['employee' => $e, 'tab' => $tab]))->assertOk();
        }
        $this->get(route('hr.attendance'))->assertOk()->assertSee('Sara');
        $this->get(route('hr.leave', ['status' => '']))->assertOk();
        $this->get(route('hr.performance', ['branch' => $this->branch->id]))->assertOk()->assertSee('Sara');

        $user->assignRole('cashier');
        $user->branches()->sync([$this->branch->id]);
        $this->actingAs($user);
        $this->get(route('hr.me'))->assertOk()->assertSee(__('Check out'));
        $this->get(route('hr.employees.show', $e))->assertForbidden();
    }
}
