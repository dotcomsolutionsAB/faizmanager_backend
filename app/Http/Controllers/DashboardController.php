<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function getDashboardStats(Request $request)
    {
        $user = Auth::user();
        $jamiatId = $user->jamiat_id;
        $userSubSectorAccess = json_decode($user->sub_sector_access_id, true); // Get user's accessible sub-sectors
    
        $year = $request->input('year');
        $requestedSectors = $request->input('sector', []);
        $requestedSubSectors = $request->input('sub_sector', []);
    
        // Validation: Allow "all" or integers
        $request->validate([
            'year' => 'required|string',
            'sector' => 'required|array',
            'sector.*' => ['required', function ($attribute, $value, $fail) {
                if ($value !== 'all' && !is_numeric($value)) {
                    $fail("The $attribute field must be an integer or the string 'all'.");
                }
            }],
            'sub_sector' => 'required|array',
            'sub_sector.*' => ['required', function ($attribute, $value, $fail) {
                if ($value !== 'all' && !is_numeric($value)) {
                    $fail("The $attribute field must be an integer or the string 'all'.");
                }
            }],
        ]);
    
        // Handle "all" for sub-sector and sector inputs
        if (in_array('all', $requestedSubSectors)) {
            $requestedSubSectors = $userSubSectorAccess; // Replace "all" with user's accessible sub-sectors
        }
        if (in_array('all', $requestedSectors)) {
            $requestedSectors = DB::table('t_sub_sector')
                ->whereIn('id', $userSubSectorAccess)
                ->distinct()
                ->pluck('sector_id')
                ->toArray(); // Replace "all" with sectors linked to user's accessible sub-sectors
        }
    
        // Ensure the requested sub-sectors match the user's access
        $subSectorFilter = array_intersect($requestedSubSectors, $userSubSectorAccess);
    
        if (empty($subSectorFilter)) {
            return response()->json([
                'message' => 'Access denied for the requested sub-sectors.',
            ], 403);
        }
    
        // Fetch sector IDs corresponding to the accessible sub-sectors
        $accessibleSectors = DB::table('t_sub_sector')
            ->whereIn('id', $subSectorFilter)
            ->distinct()
            ->pluck('sector_id')
            ->toArray();
    
        // Validate that the requested sectors match the accessible ones
        $sectorFilter = array_intersect($requestedSectors, $accessibleSectors);
    
        if (empty($sectorFilter)) {
            return response()->json([
                'message' => 'Access denied for the requested sectors.',
            ], 403);
        }
    
        // Count total accessible sectors and sub-sectors
        $totalSectorsCount = DB::table('t_sector')
            ->whereIn('id', $accessibleSectors)
            ->count();
    
        $totalSubSectorsCount = DB::table('t_sub_sector')
            ->whereIn('id', $subSectorFilter)
            ->count();
    
        // Summary Data Query
        $summaryData = DB::table('t_hub')
            ->selectRaw("
                COUNT(DISTINCT t_hub.family_id) AS total_houses,
                SUM(CASE WHEN t_hub.hub_amount = 0 THEN 1 ELSE 0 END) AS hub_not_set,
                SUM(CASE WHEN t_hub.due_amount > 0 THEN 1 ELSE 0 END) AS hub_due,
                SUM(t_hub.hub_amount) AS total_hub_amount,
                SUM(t_hub.paid_amount) AS total_paid_amount,
                SUM(t_hub.due_amount) AS total_due_amount
            ")
            ->where('t_hub.year', $year)
            ->whereExists(function ($query) use ($jamiatId, $subSectorFilter) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.family_id', 't_hub.family_id')
                    ->where('users.jamiat_id', $jamiatId)
                    ->where('users.role', 'mumeneen') // Include only mumeneen users
                    ->whereIn('users.sub_sector_id', $subSectorFilter);
            })
            ->first();
    
        // Payment Modes Query
        $paymentModes = DB::table('t_receipts')
            ->selectRaw("
                mode,
                SUM(amount) AS total_amount
            ")
            ->where('year', $year)
            ->whereExists(function ($query) use ($jamiatId, $subSectorFilter) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.family_id', 't_receipts.family_id')
                    ->where('users.jamiat_id', $jamiatId)
                    ->where('users.role', 'mumeneen') // Include only mumeneen users
                    ->whereIn('users.sub_sector_id', $subSectorFilter);
            })
            ->groupBy('mode')
            ->get();
    
        // Process Payment Modes
        $paymentBreakdown = $paymentModes->mapWithKeys(function ($item) {
            return [$item->mode => number_format($item->total_amount, 0, '.', ',')];
        });
    
        // Thaali-Taking Query
        $thaaliTakingCount = DB::table('users')
            ->where('jamiat_id', $jamiatId)
            ->where('role', 'mumeneen') // Include only mumeneen users
            ->where('thali_status', 'taking')
            ->whereIn('sub_sector_id', $subSectorFilter)
            ->distinct('family_id')
            ->count('family_id');
    
        // User Demographics Query
        $userStats = DB::table('users')
            ->selectRaw("
                COUNT(*) AS total_users,
                SUM(CASE WHEN mumeneen_type = 'HOF' THEN 1 ELSE 0 END) AS total_hof,
                SUM(CASE WHEN mumeneen_type = 'FM' THEN 1 ELSE 0 END) AS total_fm,
                SUM(CASE WHEN LOWER(gender) = 'male' THEN 1 ELSE 0 END) AS total_males,
                SUM(CASE WHEN LOWER(gender) = 'female' THEN 1 ELSE 0 END) AS total_females,
                SUM(CASE WHEN age < 13 THEN 1 ELSE 0 END) AS total_children
            ")
            ->where('jamiat_id', $jamiatId)
            ->where('role', 'mumeneen') // Include only mumeneen users
            ->whereIn('sub_sector_id', $subSectorFilter)
            ->first();
    
        // Prepare response data
        $response = [
            'year' => $year,
            'sectors' => $sectorFilter,
            'sub-sectors' => $subSectorFilter,
            'total_sectors_count' => $totalSectorsCount,
            'total_sub_sectors_count' => $totalSubSectorsCount,
            'total_houses' => number_format($summaryData->total_houses, 0, '.', ','),
            'hub_not_set' => number_format($summaryData->hub_not_set, 0, '.', ','),
            'hub_due' => number_format($summaryData->hub_due, 0, '.', ','),
            'total_hub_amount' => number_format($summaryData->total_hub_amount, 0, '.', ','),
            'total_paid_amount' => number_format($summaryData->total_paid_amount, 0, '.', ','),
            'total_due_amount' => number_format($summaryData->total_due_amount, 0, '.', ','),
            'thaali_taking' => number_format($thaaliTakingCount, 0, '.', ','),
            'total_users' => number_format($userStats->total_users, 0, '.', ','),
            'total_hof' => number_format($userStats->total_hof, 0, '.', ','),
            'total_fm' => number_format($userStats->total_fm, 0, '.', ','),
            'total_males' => number_format($userStats->total_males, 0, '.', ','),
            'total_females' => number_format($userStats->total_females, 0, '.', ','),
            'total_children' => number_format($userStats->total_children, 0, '.', ','),
            'payment_breakdown' => $paymentBreakdown,
        ];
    
        return response()->json($response);
    }

    public function getCashSummary(Request $request)
    {
        $user = Auth::user();
        $jamiatId = $user->jamiat_id;
        $userSubSectorAccess = json_decode($user->sub_sector_access_id, true); // Get user's accessible sub-sectors
    
        // Define the default year
        $defaultYear = '1445-1446';
    
        // Get the requested sectors and sub-sectors
        $requestedSectors = $request->input('sector', []);
        $requestedSubSectors = $request->input('sub_sector', []);
    
        // Validation: Allow "all" or integers
        $request->validate([
            'sector' => 'required|array',
            'sector.*' => ['required', function ($attribute, $value, $fail) {
                if ($value !== 'all' && !is_numeric($value)) {
                    $fail("The $attribute field must be an integer or the string 'all'.");
                }
            }],
            'sub_sector' => 'required|array',
            'sub_sector.*' => ['required', function ($attribute, $value, $fail) {
                if ($value !== 'all' && !is_numeric($value)) {
                    $fail("The $attribute field must be an integer or the string 'all'.");
                }
            }],
        ]);
    
        // Handle "all" for sub-sector and sector inputs
        if (in_array('all', $requestedSubSectors)) {
            $requestedSubSectors = $userSubSectorAccess; // Replace "all" with user's accessible sub-sectors
        }
        if (in_array('all', $requestedSectors)) {
            $requestedSectors = DB::table('t_sub_sector')
                ->whereIn('id', $userSubSectorAccess)
                ->distinct()
                ->pluck('sector_id')
                ->toArray(); // Replace "all" with sectors linked to user's accessible sub-sectors
        }
    
        // Ensure the requested sub-sectors match the user's access
        $subSectorFilter = array_intersect($requestedSubSectors, $userSubSectorAccess);
    
        if (empty($subSectorFilter)) {
            return response()->json([
                'message' => 'Access denied for the requested sub-sectors.',
            ], 403);
        }
    
        // Fetch sector IDs corresponding to the accessible sub-sectors
        $accessibleSectors = DB::table('t_sub_sector')
            ->whereIn('id', $subSectorFilter)
            ->distinct()
            ->pluck('sector_id')
            ->toArray();
    
        // Validate that the requested sectors match the accessible ones
        $sectorFilter = array_intersect($requestedSectors, $accessibleSectors);
    
        if (empty($sectorFilter)) {
            return response()->json([
                'message' => 'Access denied for the requested sectors.',
            ], 403);
        }
    
        // Step 1: Get cash receipts grouped by sector
        $cashReceipts = DB::table('t_receipts')
            ->select('sector_id', DB::raw('SUM(amount) as cash'))
            ->where('mode', 'cash')
            ->where('year', $defaultYear)
            ->whereIn('sector_id', $sectorFilter) // Updated to sector_id
            ->groupBy('sector_id')
            ->get();
    
        // Step 2: Get deposited payments grouped by sector
        $depositedPayments = DB::table('t_payments')
            ->select('sector_id', DB::raw('SUM(amount) as deposited'))
            ->where('mode', 'cash')
            ->where('year', $defaultYear)
            ->whereIn('sector_id', $sectorFilter) // Updated to sector_id
            ->groupBy('sector_id')
            ->get();
    
        // Step 3: Merge data to calculate in_hand
        $summary = $cashReceipts->map(function ($receipt) use ($depositedPayments) {
            $sectorPayments = $depositedPayments->firstWhere('sector_id', $receipt->sector_id);
            $deposited = $sectorPayments ? $sectorPayments->deposited : 0;
    
            return [
                'sector_id' => $receipt->sector_id,
                'cash' => $receipt->cash,
                'deposited' => $deposited,
                'in_hand' => $receipt->cash - $deposited,
            ];
        });
    
        // Include any sectors in payments that are missing in receipts
        $additionalSectors = $depositedPayments->filter(function ($payment) use ($cashReceipts) {
            return !$cashReceipts->contains('sector_id', $payment->sector_id);
        })->map(function ($payment) {
            return [
                'sector_id' => $payment->sector_id,
                'cash' => 0,
                'deposited' => $payment->deposited,
                'in_hand' => -$payment->deposited,
            ];
        });
    
        // Combine results
        $finalSummary = $summary->concat($additionalSectors);
    
        // Step 4: Return response
        return response()->json([
            'success' => true,
            'data' => $finalSummary,
        ]);
    }
    
}