<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\FileStorageServiceInterface;
use App\DataTransfer\QueueMessage;
use App\Models\BadVehicleData;
use App\Models\FileProcessingLock;
use App\Models\VehicleData;
use App\Services\Processing\VehicleFileProcessor;
use App\Services\Storage\LocalFileStorageService;
use App\Validation\VehicleRecordValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VehicleFileProcessorTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            $this->deleteTree($this->tempDir);
        }

        parent::tearDown();
    }

    private function processor(): VehicleFileProcessor
    {
        return $this->app->make(VehicleFileProcessor::class);
    }

    private function message(string $file, string $bucket = 'local-bucket'): QueueMessage
    {
        return new QueueMessage(
            messageId: 'm-'.$file,
            receiptHandle: 'r-'.$file,
            bucket: $bucket,
            folderPath: '',
            fileName: $file,
        );
    }

    public function test_processes_the_valid_sample_into_ten_persisted_vehicles(): void
    {
        $summary = $this->processor()->handle($this->message('vehicles_valid.csv'));

        self::assertSame(['processed' => 10, 'good' => 10, 'bad' => 0], $summary);
        self::assertSame(10, VehicleData::count());
        self::assertSame(0, BadVehicleData::count());

        $lock = FileProcessingLock::where('file_name', 'vehicles_valid.csv')->firstOrFail();
        self::assertSame(FileProcessingLock::STATUS_COMPLETED, $lock->status);
    }

    public function test_persists_vin_derived_fields_and_features(): void
    {
        $this->processor()->handle($this->message('vehicles_valid.csv'));

        $accord = VehicleData::where('vin', '1HGCM82633A004352')->firstOrFail();

        self::assertSame('1HG', $accord->wmi);
        self::assertSame('North America', $accord->vin_region);
        self::assertSame('United States', $accord->vin_country);
        self::assertSame('sedan', $accord->body_style);
        self::assertSame('gasoline', $accord->engine_type);
        self::assertSame(6, $accord->features['airbags']);
        self::assertNotNull($accord->last_updated);
    }

    public function test_quarantines_bad_rows_with_specific_reasons(): void
    {
        $summary = $this->processor()->handle($this->message('vehicles_mixed.csv'));

        self::assertSame(1, $summary['good']);
        self::assertSame(9, $summary['bad']);
        self::assertSame(1, VehicleData::count());
        self::assertSame(9, BadVehicleData::count());

        // The single good row is the Accord.
        self::assertSame('1HGCM82633A004352', VehicleData::firstOrFail()->vin);

        self::assertStringContainsString('check digit', $this->reason(3));
        self::assertStringContainsString('Body style', $this->reason(8));
        self::assertStringContainsString('airbags', $this->reason(9));
        self::assertStringContainsString('Manufacture date', $this->reason(11));

        $bad = BadVehicleData::where('row_number', 8)->firstOrFail();
        self::assertSame('vehicles_mixed.csv', $bad->file_name);
        self::assertNotSame('', $bad->raw_row_data);
    }

    /**
     * Bug 1 regression: a later file that updates an existing VIN must refresh
     * the stored row in place (no duplicate, no SQL error from the old `color`
     * column). Fails against the pre-fix upsert `update` list.
     */
    public function test_later_file_updates_an_existing_vehicle_in_place(): void
    {
        $this->tempDir = sys_get_temp_dir().'/vdh-proc-'.bin2hex(random_bytes(4));
        mkdir($this->tempDir.'/local-bucket', 0775, true);
        $this->app->instance(
            FileStorageServiceInterface::class,
            new LocalFileStorageService($this->tempDir),
        );

        file_put_contents(
            $this->tempDir.'/local-bucket/first.csv',
            $this->accordCsv(price: 28999, mileage: 15000),
        );
        file_put_contents(
            $this->tempDir.'/local-bucket/second.csv',
            $this->accordCsv(price: 19999, mileage: 22000),
        );

        $processor = $this->processor();
        $processor->handle($this->message('first.csv'));
        $processor->handle($this->message('second.csv'));

        self::assertSame(1, VehicleData::count(), 'the VIN must be upserted, not duplicated');

        $vehicle = VehicleData::where('vin', '1HGCM82633A004352')->firstOrFail();
        self::assertEqualsWithDelta(19999.0, (float) $vehicle->current_price_usd, 0.001);
        self::assertSame(22000, $vehicle->mileage);
    }

    private function reason(int $rowNumber): string
    {
        return (string) BadVehicleData::where('row_number', $rowNumber)->firstOrFail()->error_reason;
    }

    private function accordCsv(int $price, int $mileage): string
    {
        $header = implode(',', VehicleRecordValidator::HEADER);
        $features = '"{""airbags"":6,""sunroof"":true,""headlamps"":""led""}"';

        $row = implode(',', [
            '1HGCM82633A004352', 'Accord', '2022', 'EX-L', 'sedan',
            (string) $price, '31000', 'true', '560001', 'false',
            (string) $mileage, '1', 'Blue', 'Black', 'gasoline',
            'automatic', '32.5', '2021-11-05', '2022-01-10', $features,
        ]);

        return $header."\n".$row."\n";
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
