<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\MachineSource;
use App\Enums\MaintenanceLogSource;
use App\Enums\MaintenanceLogStatus;
use App\Enums\ProductTerm;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use App\Models\Product;
use App\Models\User;
use App\Services\Maintenance\MaintenanceScheduler;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Phase 10 — imports the 227 real installed machines + maintenance
 * history from `Database for devlpt.xlsx`.
 *
 * Idempotent (re-running creates 0 new rows). Env-gated to
 * local/development/testing — production runs are out of scope. If the
 * Excel file is missing the seeder logs a warning and short-circuits
 * (CI bootstraps without it).
 *
 * Spec: docs/architecture/machines-maintenance.md § 9.
 */
final class MachinesFromExcelSeeder extends Seeder
{
    /**
     * Sheet name → ISO alpha-2 country code (stored lowercase per
     * Phase 3's CountriesAndRegionsSeeder).
     */
    private const SHEET_TO_COUNTRY = [
        'Database-UAE' => 'ae',
        'Database-Qatar' => 'qa',
        'Database-Kwt' => 'kw',
    ];

    private const COUNTRY_CITY = [
        'ae' => 'Dubai',
        'qa' => 'Doha',
        'kw' => 'Kuwait City',
    ];

    private const COUNTRY_PHONE_PREFIX = [
        'ae' => '+971',
        'qa' => '+974',
        'kw' => '+965',
    ];

    /**
     * The first 2 rows are headers/spacers. Maintenance dates start at
     * column J and continue up to column AB (19 columns).
     */
    private const HEADER_ROW_SKIP = 2;

    private const MAINTENANCE_COLUMNS = [
        'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R',
        'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB',
    ];

    private int $machineCount = 0;

    private int $logCount = 0;

    private int $customerCount = 0;

    private int $phoneSequence = 1;

    /**
     * Cache: country code → countries.id.
     *
     * @var array<string, int>
     */
    private array $countryIds = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            return;
        }

        $path = $this->resolveExcelPath();
        if ($path === null) {
            return;
        }

        try {
            /** @var Spreadsheet $spreadsheet */
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $e) {
            Log::warning('[MachinesFromExcelSeeder] Failed to open Excel file.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->countryIds = Country::query()
            ->pluck('id', 'code')
            ->all();

        foreach (self::SHEET_TO_COUNTRY as $sheetName => $countryCode) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if ($sheet === null) {
                Log::warning('[MachinesFromExcelSeeder] Sheet missing — skipping.', [
                    'sheet' => $sheetName,
                ]);

                continue;
            }

            if (! isset($this->countryIds[$countryCode])) {
                Log::warning('[MachinesFromExcelSeeder] Country missing — skipping sheet.', [
                    'country' => $countryCode,
                    'sheet' => $sheetName,
                ]);

                continue;
            }

            $maxRow = $sheet->getHighestDataRow();
            for ($rowNumber = self::HEADER_ROW_SKIP + 1; $rowNumber <= $maxRow; $rowNumber++) {
                $this->processRow($sheet, $rowNumber, $sheetName, $countryCode);
            }
        }

        $this->refreshAllNextDueDates();

        $message = sprintf(
            'MachinesFromExcelSeeder: imported %d machines, %d maintenance logs, %d unique customers.',
            $this->machineCount,
            $this->logCount,
            $this->customerCount,
        );

        Log::info($message);
        if ($this->command !== null) {
            $this->command->info($message);
        }
    }

    /**
     * Copy from project root into storage if needed, returning the
     * resolved storage path (or null when neither source exists).
     */
    private function resolveExcelPath(): ?string
    {
        $storagePath = storage_path('app/seed/excel/Database for devlpt.xlsx');
        if (File::exists($storagePath)) {
            return $storagePath;
        }

        $rootPath = base_path('Database for devlpt.xlsx');
        if (! File::exists($rootPath)) {
            Log::warning('[MachinesFromExcelSeeder] Excel file not found — skipping seeder.', [
                'tried' => [$storagePath, $rootPath],
            ]);

            return null;
        }

        $storageDir = storage_path('app/seed/excel');
        if (! File::exists($storageDir)) {
            File::makeDirectory($storageDir, 0755, true);
        }
        File::copy($rootPath, $storagePath);

        return $storagePath;
    }

    private function processRow(
        Worksheet $sheet,
        int $rowNumber,
        string $sheetName,
        string $countryCode,
    ): void {
        $location = $this->cell($sheet, 'B', $rowNumber);
        $unitType = $this->cell($sheet, 'C', $rowNumber);
        $serialRaw = $this->cell($sheet, 'D', $rowNumber);
        $termRaw = $this->cell($sheet, 'E', $rowNumber);

        $installCell = $sheet->getCell('F'.$rowNumber);
        try {
            $installRaw = $installCell->getCalculatedValue();
        } catch (Throwable) {
            $installRaw = $installCell->getValue();
        }

        $filterIntervalRaw = $this->cell($sheet, 'G', $rowNumber);
        $emailH = $this->cell($sheet, 'H', $rowNumber);
        $emailI = $this->cell($sheet, 'I', $rowNumber);

        // Skip blank rows (no location AND no unit type AND no install date).
        $installEmpty = $installRaw === null || $installRaw === '';
        if ($location === '' && $unitType === '' && $serialRaw === '' && $installEmpty) {
            return;
        }

        if ($unitType === '') {
            Log::warning('[MachinesFromExcelSeeder] Missing Unit Type — skipping row.', [
                'sheet' => $sheetName,
                'row' => $rowNumber,
            ]);

            return;
        }

        $productCode = strtoupper(trim($unitType));
        $product = Product::query()->where('code', $productCode)->first();
        if ($product === null) {
            Log::warning('[MachinesFromExcelSeeder] Unknown product code — skipping row.', [
                'sheet' => $sheetName,
                'row' => $rowNumber,
                'code' => $productCode,
            ]);

            return;
        }

        $installedAt = $this->parseExcelDate($installRaw);
        if ($installedAt === null) {
            Log::warning('[MachinesFromExcelSeeder] Unparseable install date — skipping row.', [
                'sheet' => $sheetName,
                'row' => $rowNumber,
            ]);

            return;
        }

        $email = $this->resolveEmail($emailH, $emailI, $countryCode, $rowNumber);
        $customerName = $this->resolveCustomerName($location);
        $contactName = $this->resolveContactName($emailH, $emailI);
        $contactEmail = $this->resolveContactEmail($emailH, $emailI);

        try {
            DB::transaction(function () use (
                $sheet, $rowNumber, $sheetName, $countryCode, $product, $serialRaw,
                $termRaw, $installedAt, $filterIntervalRaw, $location, $email, $customerName,
                $contactName, $contactEmail,
            ): void {
                $user = $this->firstOrCreateUser($email, $customerName, $contactName, $contactEmail, $countryCode);
                $address = $this->firstOrCreateAddress($user, $location, $countryCode);

                $serial = $this->resolveSerial($serialRaw, $sheetName, $rowNumber);
                $term = $this->normaliseTerm($termRaw);
                $interval = $this->parseFilterInterval($filterIntervalRaw);

                $machine = Machine::query()->firstOrCreate(
                    ['serial_number' => $serial],
                    [
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'address_id' => $address->id,
                        'product_id' => $product->id,
                        'term' => $term->value,
                        'installed_at' => $installedAt->toDateString(),
                        'filter_change_interval_months' => $interval,
                        'next_maintenance_due_at' => null,
                        'location_label' => $location !== '' ? $location : null,
                        'country_code' => strtolower($countryCode),
                        'is_active' => true,
                        'source' => MachineSource::Imported->value,
                        'order_id' => null,
                        'order_installation_id' => null,
                    ],
                );

                if ($machine->wasRecentlyCreated) {
                    $this->machineCount++;
                }

                $this->importMaintenanceLogs($sheet, $rowNumber, $machine);
            });
        } catch (Throwable $e) {
            Log::warning('[MachinesFromExcelSeeder] Row failed — continuing.', [
                'sheet' => $sheetName,
                'row' => $rowNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read a cell as a trimmed string. Handles PhpSpreadsheet's RichText
     * cells (the H column in the production workbook contains them) by
     * using the plain-text projection.
     */
    private function cell(
        Worksheet $sheet,
        string $column,
        int $row,
    ): string {
        $value = $sheet->getCell($column.$row)->getValue();
        if ($value === null) {
            return '';
        }

        if ($value instanceof RichText) {
            return trim($value->getPlainText());
        }

        return trim((string) $value);
    }

    private function parseExcelDate(mixed $raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            if (is_numeric($raw)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw));
            }

            $string = trim((string) $raw);
            if ($string === '') {
                return null;
            }

            return Carbon::parse($string);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseFilterInterval(string $raw): ?int
    {
        if ($raw === '') {
            return null;
        }

        $clean = strtolower(trim($raw));
        if (preg_match('/^(\d+)\s*month/', $clean, $matches) === 1) {
            $value = (int) $matches[1];

            return $value > 0 && $value <= 255 ? $value : null;
        }

        // Numeric-only fallback (e.g. cell stored as 3).
        if (is_numeric($clean)) {
            $value = (int) $clean;

            return $value > 0 && $value <= 255 ? $value : null;
        }

        return null;
    }

    private function normaliseTerm(string $raw): ProductTerm
    {
        return match (strtolower(trim($raw))) {
            'buy' => ProductTerm::Buy,
            'rent' => ProductTerm::Rent,
            'foc' => ProductTerm::Foc,
            default => ProductTerm::Buy,
        };
    }

    private function resolveSerial(string $raw, string $sheetName, int $rowNumber): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return 'PENDING-MIGRATED-'.$sheetName.'-'.$rowNumber;
        }

        return Str::limit($trimmed, 80, '');
    }

    private function resolveEmail(string $h, string $i, string $countryCode, int $rowNumber): string
    {
        return $this->resolveContactEmail($h, $i) ?? sprintf(
            'unknown+row%d-%s@pwt.local',
            $rowNumber,
            strtolower($countryCode),
        );
    }

    private function resolveCustomerName(string $location): string
    {
        return Str::limit($location !== '' ? $location : 'PWT Customer', 120, '');
    }

    private function resolveContactEmail(string $h, string $i): ?string
    {
        foreach ([trim($h), trim($i)] as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return strtolower($candidate);
            }
        }

        return null;
    }

    /**
     * Decide what lands in `first_name` / `last_name`.
     *
     * The contact person (column H/I) owns those columns. Rows where both
     * cells are blank or hold only an email fall back to splitting the
     * customer label so the columns are never left empty.
     *
     * @return array{0: string, 1: string|null}
     */
    private function resolvePersonName(?string $contactName, string $customerName): array
    {
        if ($contactName !== null && trim($contactName) !== '') {
            return $this->splitContactName($contactName);
        }

        return $this->splitName($customerName);
    }

    /**
     * Split a contact person's name into first / last on whitespace —
     * the leading word becomes `first_name`, the remainder `last_name`
     * ("Mhd Kamran Qureshi" → "Mhd" + "Kamran Qureshi").
     *
     * Cells holding several contacts ("Rabih Abboud / Larissa") belong to
     * one account row, so only the first person is used; the raw value is
     * still preserved in full on `contact_name`.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitContactName(string $contactName): array
    {
        $segments = preg_split('#\s*/\s*#u', $contactName, 2);
        $primary = trim((string) ($segments[0] ?? ''));
        if ($primary === '') {
            $primary = trim($contactName);
        }

        $parts = preg_split('/\s+/u', $primary, 2);
        $first = trim((string) ($parts[0] ?? ''));
        $last = trim((string) ($parts[1] ?? ''));

        return [
            Str::limit($first !== '' ? $first : $primary, 60, ''),
            $last !== '' ? Str::limit($last, 60, '') : null,
        ];
    }

    /**
     * Split an imported customer label on its dash — the workbook stores
     * them as "Brand- Branch" (e.g. "Paul Cafe- Dubai Mall"), so the part
     * before the dash becomes `first_name` and the part after becomes
     * `last_name`.
     *
     * The dash must be padded by whitespace on at least one side,
     * otherwise hyphenated words ("V-Kool Emirates", "Omar Al-Sai") would
     * be torn apart. A bare dash is only honoured when the whole label is
     * exactly two dash-joined words ("NBK-Headquarters"). Labels with no
     * usable dash keep the full value as `first_name` and leave
     * `last_name` null.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitName(string $name): array
    {
        $first = $name;
        $last = '';

        $parts = preg_split('/(?:\s+[-–—]\s*|[-–—]\s+)/u', $name, 2);
        if (is_array($parts) && count($parts) === 2) {
            $first = $parts[0];
            $last = $parts[1];
        } elseif (preg_match('/^([^\s\-–—]+)[-–—]([^\-–—]+)$/u', $name, $matches) === 1) {
            $first = $matches[1];
            $last = $matches[2];
        }

        $first = trim($first);
        $last = trim($last);

        return [
            Str::limit($first !== '' ? $first : $name, 60, ''),
            $last !== '' ? Str::limit($last, 60, '') : null,
        ];
    }

    private function resolveContactName(string $h, string $i): ?string
    {
        foreach ([trim($h), trim($i)] as $candidate) {
            if ($candidate !== '' && ! filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return Str::limit($candidate, 120, '');
            }
        }

        return null;
    }

    private function firstOrCreateUser(
        string $email,
        string $name,
        ?string $contactName,
        ?string $contactEmail,
        string $countryCode,
    ): User {
        [$firstName, $lastName] = $this->resolvePersonName($contactName, $name);

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            // Backfill rows imported before the split-name / company
            // columns existed. Only touched while both name parts are
            // still empty, so an operator's manual edits are never
            // overwritten on a re-run.
            if ($existing->first_name === null && $existing->last_name === null) {
                [$existingFirst, $existingLast] = $this->resolvePersonName(
                    $existing->contact_name,
                    $existing->name,
                );
                $existing->update([
                    'first_name' => $existingFirst,
                    'last_name' => $existingLast,
                    'account_type' => AccountType::Company->value,
                    'company_name' => $existing->name,
                ]);
            }

            return $existing;
        }

        $phone = $this->generateSyntheticPhone();
        $countryPrefix = self::COUNTRY_PHONE_PREFIX[$countryCode] ?? '+971';

        $user = User::query()->create([
            'name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'account_type' => AccountType::Company->value,
            'company_name' => $name,
            'email' => $email,
            'phone_country_code' => $countryPrefix,
            'phone' => $phone,
            'locale' => 'en',
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => Hash::make('password123'),
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
        ]);

        $user->assignRole(UserRole::Customer->value);
        $this->customerCount++;

        return $user;
    }

    private function generateSyntheticPhone(): string
    {
        do {
            $candidate = '5'.str_pad((string) $this->phoneSequence, 8, '0', STR_PAD_LEFT);
            $this->phoneSequence++;
            $exists = User::query()->where('phone', $candidate)->exists();
        } while ($exists);

        return $candidate;
    }

    private function firstOrCreateAddress(User $user, string $location, string $countryCode): Address
    {
        $countryId = $this->countryIds[$countryCode] ?? null;
        if ($countryId === null) {
            throw new \RuntimeException("Country $countryCode not seeded.");
        }

        $label = $location !== '' ? Str::limit($location, 60, '') : 'Installation Site';
        $city = self::COUNTRY_CITY[$countryCode] ?? 'Unknown';
        $phonePrefix = self::COUNTRY_PHONE_PREFIX[$countryCode] ?? '+971';

        $existing = Address::query()
            ->where('user_id', $user->id)
            ->where('label', $label)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $isDefault = ! Address::query()
            ->where('user_id', $user->id)
            ->exists();

        return Address::query()->create([
            'user_id' => $user->id,
            'country_id' => $countryId,
            'region_id' => null,
            'label' => $label,
            'recipient_name' => $user->name,
            'recipient_phone' => $user->phone ?? '500000000',
            'recipient_phone_country_code' => $phonePrefix,
            'line1' => $location !== '' ? Str::limit($location, 200, '') : 'Installation site',
            'city' => $city,
            'is_default' => $isDefault,
        ]);
    }

    private function importMaintenanceLogs(
        Worksheet $sheet,
        int $rowNumber,
        Machine $machine,
    ): void {
        foreach (self::MAINTENANCE_COLUMNS as $column) {
            $cell = $sheet->getCell($column.$rowNumber);
            // Many maintenance cells are formulas (`=L3+90`). Calculated
            // values resolve to the numeric Excel serial we then parse.
            try {
                $raw = $cell->getCalculatedValue();
            } catch (Throwable) {
                $raw = $cell->getValue();
            }
            $date = $this->parseExcelDate($raw);
            if ($date === null) {
                continue;
            }

            $log = MachineMaintenanceLog::query()->firstOrCreate(
                [
                    'machine_id' => $machine->id,
                    'scheduled_at' => $date->toDateString(),
                    'source' => MaintenanceLogSource::Imported->value,
                ],
                [
                    'status' => MaintenanceLogStatus::Completed->value,
                    'completed_at' => $date->toDateString(),
                    'technician_user_id' => null,
                    'notes' => 'Imported from production records',
                    'created_by_user_id' => null,
                    'maintenance_request_id' => null,
                ],
            );

            if ($log->wasRecentlyCreated) {
                $this->logCount++;
            }
        }
    }

    /**
     * After all rows are imported, recompute every machine's
     * `next_maintenance_due_at` so the customer list endpoint surfaces
     * the right "needs maintenance" flag from day one.
     */
    private function refreshAllNextDueDates(): void
    {
        /** @var MaintenanceScheduler $scheduler */
        $scheduler = app(MaintenanceScheduler::class);

        Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->orderBy('id')
            ->chunkById(200, function ($machines) use ($scheduler): void {
                foreach ($machines as $machine) {
                    $due = $scheduler->computeNextDue($machine);
                    $machine->update([
                        'next_maintenance_due_at' => $due?->toDateString(),
                    ]);
                }
            });
    }
}
