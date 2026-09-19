<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\LabOrderController;
use App\Http\Controllers\Api\LabTestController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PharmacySalesController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\VisitBillController;
use App\Http\Controllers\Api\WorksheetController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/register-patient', [AuthController::class, 'registerPatient']);
    Route::post('/patient-login', [AuthController::class, 'patientLogin']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/reset-password-with-otp', [AuthController::class, 'resetPasswordWithOtp']);
    Route::post('/dev-login/{role}', [AuthController::class, 'devLogin']);
});

Route::get('/clinics', [ClinicController::class, 'index']);
Route::get('/departments', [DepartmentController::class, 'index']);
Route::get('/doctors', [DoctorController::class, 'index']);
Route::get('/doctors/{doctor}/availability', [DoctorController::class, 'getAvailability']);
Route::get('/doctors/{doctor}/available-dates', [DoctorController::class, 'availableDates']);
Route::get('/appointments/slots', [AppointmentController::class, 'slots']);
Route::get('/queue/status', [QueueController::class, 'status']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    Route::get('/appointments/day-schedule', [AppointmentController::class, 'daySchedule']);
    Route::get('/appointments/calendar', [AppointmentController::class, 'calendar']);

    Route::get('/patients/me', [PatientController::class, 'me']);
    Route::patch('/patients/me', [PatientController::class, 'updateMe']);
    Route::get('/patients/my-patients', [PatientController::class, 'myPatients']);
    Route::post('/patients/{patient}/issue-login', [PatientController::class, 'issueLogin']);
    Route::get('/patients/search', [PatientController::class, 'search']);
    Route::apiResource('patients', PatientController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    // Multipart photo updates (FormData) — mirrors doctor update pattern.
    Route::post('/patients/{patient}', [PatientController::class, 'update']);
    // Fallback when DELETE is blocked by a proxy.
    Route::post('/patients/{patient}/delete', [PatientController::class, 'destroy']);

    Route::post('/doctors', [DoctorController::class, 'store']);
    Route::post('/doctors/{doctor}', [DoctorController::class, 'update']);
    Route::post('/doctors/{doctor}/reset-password', [DoctorController::class, 'resetPassword']);
    Route::patch('/doctors/{doctor}/approve', [DoctorController::class, 'approve']);
    Route::patch('/doctors/{doctor}/deactivate', [DoctorController::class, 'deactivate']);
    Route::post('/doctors/{doctor}/availability', [DoctorController::class, 'setAvailability']);

    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::patch('/appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn']);
    Route::patch('/appointments/{appointment}/check-out', [AppointmentController::class, 'checkOut']);
    Route::patch('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    Route::patch('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule']);

    Route::post('/queue/join', [QueueController::class, 'join']);
    Route::get('/queue/day-board', [QueueController::class, 'dayBoard']);
    Route::get('/queue/my-position', [QueueController::class, 'myPosition']);
    Route::get('/queue/my-active', [QueueController::class, 'myActive']);
    Route::post('/queue/call-next', [QueueController::class, 'callNext']);
    Route::patch('/queue/{id}/check-in', [QueueController::class, 'checkIn']);
    Route::patch('/queue/{id}/check-out', [QueueController::class, 'checkOut']);
    Route::patch('/queue/{id}/complete', [QueueController::class, 'complete']);
    Route::patch('/queue/{id}/cancel', [QueueController::class, 'cancel']);

    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/peak-booking-hours', [DashboardController::class, 'peakBookingHours']);

    Route::get('/medicines', [MedicineController::class, 'index']);
    Route::post('/medicines/resolve', [MedicineController::class, 'resolve']);
    Route::post('/medicines', [MedicineController::class, 'store']);
    Route::patch('/medicines/{medicine}', [MedicineController::class, 'update']);

    Route::get('/prescriptions', [PrescriptionController::class, 'index']);
    Route::post('/prescriptions', [PrescriptionController::class, 'store']);
    Route::get('/prescriptions/{prescription}', [PrescriptionController::class, 'show']);
    Route::post('/prescriptions/{prescription}/dispense', [PrescriptionController::class, 'dispense']);

    Route::get('/worksheets', [WorksheetController::class, 'index']);
    Route::get('/worksheets/by-token/{tokenId}', [WorksheetController::class, 'showByToken']);
    Route::get('/worksheets/{worksheet}', [WorksheetController::class, 'show']);
    Route::patch('/worksheets/{worksheet}', [WorksheetController::class, 'update']);

    Route::get('/lab-tests', [LabTestController::class, 'index']);
    Route::post('/lab-tests', [LabTestController::class, 'store']);
    Route::get('/lab-orders', [LabOrderController::class, 'index']);
    Route::post('/lab-orders', [LabOrderController::class, 'store']);
    Route::get('/lab-orders/{labOrder}', [LabOrderController::class, 'show']);
    Route::post('/lab-orders/{labOrder}/report', [LabOrderController::class, 'uploadReport']);
    Route::patch('/lab-orders/{labOrder}/results', [LabOrderController::class, 'saveResults']);

    Route::get('/visit-bills', [VisitBillController::class, 'index']);
    Route::get('/visit-bills/{visitBill}', [VisitBillController::class, 'show']);
    Route::patch('/visit-bills/{visitBill}/pharmacy-items', [VisitBillController::class, 'updatePharmacyItems']);
    Route::patch('/visit-bills/{visitBill}/lab-items', [VisitBillController::class, 'updateLabItems']);
    Route::post('/visit-bills/{visitBill}/pharmacy-done', [VisitBillController::class, 'markPharmacyDone']);
    Route::post('/visit-bills/{visitBill}/lab-done', [VisitBillController::class, 'markLabDone']);
    Route::get('/pharmacy/sales', [PharmacySalesController::class, 'index']);

    Route::get('/staff', [StaffController::class, 'index']);
    Route::post('/staff/receptionist', [StaffController::class, 'storeReceptionist']);
});
