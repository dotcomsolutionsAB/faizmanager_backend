<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;
use League\Csv\Statement;
use App\Models\User;
use App\Models\ItsModel;
use App\Models\PaymentsModel;
use App\Models\ReceiptsModel;
use App\Models\HubModel;
use App\Models\BuildingModel;
use Http;

// use DB;

class CSVImportController extends Controller
{     public function importDataFromUrl()
    {
        // URLs of the CSV files
        $receiptCsvUrl = public_path('storage/Receipts.csv'); // Replace with actual URL
        $paymentCsvUrl = public_path('storage/Payments.csv'); // Replace with actual URL
    
        try {
            // Fetch sector and sub-sector mappings
            $sectorMapping = DB::table('t_sector')->pluck('id', 'name')->toArray();
            $subSectorMapping = DB::table('t_sub_sector')
                ->select('id', 'name', 'sector')
                ->get()
                ->mapWithKeys(function ($item) {
                    return ["{$item->sector}:{$item->name}" => $item->id];
                })
                ->toArray();
    
            // Process Receipt CSV
            $this->processReceiptCSV($receiptCsvUrl, $sectorMapping, $subSectorMapping);
    
            // Process Payment CSV
            $this->processPaymentCSV($paymentCsvUrl, $sectorMapping, $subSectorMapping);
    
            return response()->json(['message' => 'Data import from URLs completed successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    private function processReceiptCSV($url, $sectorMapping, $subSectorMapping)
    {
        // Clear existing data in the receipt table
        ReceiptsModel::truncate();
    
        // Fetch the CSV content from the URL
        $csvContent = file_get_contents($url);
        if ($csvContent === false) {
            throw new \Exception("Failed to fetch the CSV content from the URL: $url");
        }
    
        // Read and parse the CSV
        $csv = Reader::createFromString($csvContent);
        $csv->setHeaderOffset(0);
    
        // Get records and initialize batch variables
        $receiptRecords = $csv->getRecords();
        $batchSize = 100;
        $batchData = [];
    
        foreach ($receiptRecords as $record) {
            // Map sector and sub-sector IDs
            $sectorId = $sectorMapping[$record['sector']] ?? null;
            $subSectorId = $subSectorMapping["{$record['sector']}:{$record['sub_sector']}"] ?? null;
    
            // Determine status using the old fields
            $statusFlag = (int) $record['status']; // 0 = active, 1 = cancelled
            $paymentStatus = (int) $record['payment_status']; // 0 = pending, 1 = paid
    
            // Merge status and payment_status into the new enum status
            if ($statusFlag === 1) {
                $status = 'cancelled'; // If status is 1, set to 'cancelled'
            } else {
                $status = $paymentStatus === 1 ? 'paid' : 'pending'; // Otherwise, use payment_status
            }
    
            // Convert type to mode in lowercase
            $mode = strtolower($record['type']);
    
            // Ensure mode is one of the enum values
            $allowedModes = ['cheque', 'cash', 'neft', 'upi'];
            if (!in_array($mode, $allowedModes)) {
                $mode = 'cash'; // Default to 'cash' if not in the allowed modes
            }
    
            // Prepare receipt data
            $batchData[] = [
                'jamiat_id' => 1,
                'family_id' => $record['family_id'],
                'receipt_no' => $record['rno'],
                'date' => $this->formatDate($record['date']),
                'folio_no' => $record['folio'],
                'name' => $record['name'],
                'its' => $record['its'],
                'sector_id' => $sectorId, // Mapped sector ID
                'sub_sector_id' => $subSectorId, // Mapped sub-sector ID
                'mode' => $mode, // Updated field
                'amount' => preg_replace('/[^\d.]/', '', $record['amount']),
                'year' => $record['year'],
                'comments' => $record['comments'],
                'status' => $status,
                'cancellation_reason' => $status === 'cancelled' ? $record['reason'] : null,
                'log_user' => $record['log_user'],
                'created_at' => $record['log_date'],
            ];
    
            if (count($batchData) >= $batchSize) {
                $this->insertBatch(ReceiptsModel::class, $batchData);
                $batchData = [];
            }
        }
    
        if (count($batchData) > 0) {
            $this->insertBatch(ReceiptsModel::class, $batchData);
        }
    }
    
    private function processPaymentCSV($url, $sectorMapping, $subSectorMapping)
    {
        // Clear existing data in the payment table
        PaymentsModel::truncate();
    
        // Fetch the CSV content from the URL
        $csvContent = file_get_contents($url);
        if ($csvContent === false) {
            throw new \Exception("Failed to fetch the CSV content from the URL: $url");
        }
    
        // Read and parse the CSV
        $csv = Reader::createFromString($csvContent);
        $csv->setHeaderOffset(0);
        $counter = 0;
    
        // Get records and initialize batch variables
        $paymentRecords = $csv->getRecords();
        $batchSize = 100;
        $batchData = [];
    
        foreach ($paymentRecords as $record) {
            // Map sector and sub-sector IDs
            $sectorId = $sectorMapping[$record['sector']] ?? null;
            $subSectorId = $subSectorMapping["{$record['sector']}:{$record['sub_sector']}"] ?? null;
    
            // Format payment_no as "P_counter_(date)"
            $formattedDate = $this->formatDate($record['date']);
            $paymentNo = "P_'$counter'_'$formattedDate'";
            $counter++;
    
            $formattedDate = date('Y-m-d', strtotime($record['date']));
            $date = $this->validateAndFormatDate($record['date']);
    
            // Prepare payment data
            $batchData[] = [
                'jamiat_id' => 1,
                'family_id' => $record['family_id'],
                'folio_no' => $record['folio'],
                'name' => $record['name'],
                'its' => $record['its'],
                'sector_id' => $sectorId, // Mapped sector ID
                'sub_sector_id' => $subSectorId, // Mapped sub-sector ID
                'year' => $record['year'],
                'mode' => strtolower($record['mode']),
                'date' => $this->formatDate($date),
                'bank_name' => $record['bank_name'] ?? null,
                'cheque_no' => $record['cheque_num'] ?? null,
                'cheque_date' => null,
                'ifsc_code' => $record['ifsc'] ?? null,
                'amount' => preg_replace('/[^\d.]/', '', $record['amount']),
                'comments' => $record['comments'] ?? null,
                'status' => 'pending',
                'cancellation_reason' => null,
                'log_user' => $record['log_user'],
                'attachment' => null,
                'payment_no' => $paymentNo,
            ];
    
            if (count($batchData) >= $batchSize) {
                $this->insertBatch(PaymentsModel::class, $batchData);
                $batchData = [];
            }
        }
    
        if (count($batchData) > 0) {
            $this->insertBatch(PaymentsModel::class, $batchData);
        }
    }
    private function formatDate($date)
{
    // Early check for problematic or empty dates
    if (empty($date) || $date === '-0001-11-30' || $date === '0000-00-00') {
        return '2021-12-12'; // Default to a valid date
    }

    // Check if the date is valid using DateTime
    try {
        $formattedDate = (new \DateTime($date))->format('Y-m-d');
        return $formattedDate;
    } catch (\Exception $e) {
        // Return a default valid date if parsing fails
        return '2021-12-12';
    }
}
private function insertBatch($model, $data)
{
    try {
        // Check if data is valid
        if (!is_array($data) || empty($data) || !is_array(reset($data))) {
            throw new \Exception("Invalid data format for batch insert. Expected an array of arrays.");
        }

        // Disable query logging to improve performance for large batches
        DB::connection()->disableQueryLog();

        // Insert the data into the given model's table
        $model::insert($data);
    } catch (\Exception $e) {
        throw new \Exception("Error inserting batch: " . $e->getMessage());
    }
}




    }
  