<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$appt = \App\Models\Appointment::latest('id')->first();
echo "Current casts serialization:\n";
echo "toArray: " . json_encode($appt->toArray()['appointment_date']) . "\n";

class TestAppt extends \App\Models\Appointment {
    protected $table = 'appointments';
    protected function casts(): array {
        return [
            'appointment_date' => 'date:Y-m-d',
        ];
    }
}

$test = TestAppt::find($appt->id);
echo "With date:Y-m-d casts:\n";
echo "toArray: " . json_encode($test->toArray()['appointment_date']) . "\n";
