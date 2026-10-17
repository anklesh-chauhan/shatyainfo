<?php

namespace App\Models;

use App\Notifications\UserAccountCreated;
use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Employee extends BaseModel
{
    public bool $provisionLoginOnSave = true;

    use HasApprovalWorkflow, SoftDeletes;

    protected $primaryKey = 'id';

    protected $fillable = [
        'employee_id', 'first_name', 'middle_name', 'last_name', 'email', 'mobile_number',
        'date_of_birth', 'gender', 'country_id', 'marital_status', 'phone_number',
        'emergency_contact_name', 'emergency_contact_number', 'age', 'contact_details',
        'profile_picture', 'blood_group', 'is_active', 'login_id', 'created_by_user_id',
        'updated_by_user_id', 'deleted_by_user_id', 'is_deleted', 'shift_master_id', 'approval_status',
    ];

    protected $casts = [
        'gender' => 'string',
        'marital_status' => 'string',
        'blood_group' => 'string',
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
        'date_of_birth' => 'date',
    ];

    protected $appends = ['full_name'];

    public function attendance()
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    public function bankDetail()
    {
        return $this->morphMany(BankDetail::class, 'bankable');
    }

    public function getFullNameAttribute()
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    public function getAgeAttribute()
    {
        return $this->date_of_birth ? now()->diffInYears($this->date_of_birth) : null;
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'login_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function employmentDetail()
    {
        return $this->hasOne(EmploymentDetail::class, 'employee_id');
    }

    public function professionalTax()
    {
        return $this->hasMany(EmpProfessionalTax::class, 'employee_id');
    }

    public function statutoryIds()
    {
        return $this->hasOne(EmpStatutoryId::class, 'employee_id');
    }

    public function qualifications()
    {
        return $this->hasMany(EmpQualification::class, 'employee_id');
    }

    public function skills()
    {
        return $this->hasMany(EmpSkill::class, 'employee_id');
    }

    public function departments()
    {
        return $this->hasMany(EmpDepartment::class, 'department_head_id');
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'employee_position_pivot', 'employee_id', 'position_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function shiftAssignments()
    {
        return $this->hasMany(EmployeeShift::class);
    }

    public function weekOffs()
    {
        return $this->hasMany(WeekOff::class);
    }

    public function currentShiftForDate($date = null)
    {
        $date ??= today();

        return $this->shiftAssignments()
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first()?->shift;
    }

    protected static function booted(): void
    {
        static::saved(function (Employee $employee) {

            if (! $employee->provisionLoginOnSave) {
                return;
            }

            // ✅ Already has login
            if ($employee->login_id) {
                return;
            }

            // ✅ Email is mandatory
            if (! $employee->email) {
                return;
            }

            DB::transaction(function () use ($employee) {

                // 🔁 Check if user already exists
                $user = User::where('email', $employee->email)->first();

                $password = null;

                if (! $user) {
                    $password = Str::random(10);

                    $user = User::create([
                        'name' => trim($employee->first_name.' '.$employee->last_name),
                        'email' => $employee->email,
                        'password' => bcrypt($password),
                    ]);

                    // Optional: assign default role
                    // $user->assignRole('employee');
                }

                // 🔗 Link user to employee
                $employee->login_id = $user->id;

                // ❗ Prevent infinite loop
                $employee->saveQuietly();

                // 📧 Send credentials ONLY if user was newly created
                if ($password) {
                    $user->notify(new UserAccountCreated($password));
                }
            });
        });
    }
}
