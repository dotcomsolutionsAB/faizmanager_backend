<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Auth;

class SyncController extends Controller
{
    /**
     * Scenario 1: Detect HOF present in t_its_data but missing in users.
     */
    public function detectMissingHofInUsers()
    {
        $missingHofs = DB::table('t_its_data')
            ->select('t_its_data.its', 't_its_data.name', 't_its_data.mobile', 't_its_data.age', 't_its_data.hof_its')
            ->leftJoin('users', 't_its_data.its', '=', 'users.its')
            ->whereNull('users.its')
            ->whereColumn('t_its_data.its', 't_its_data.hof_its')
            ->get();

        return response()->json([
            'message' => 'Missing HOFs detected.',
            'data' => $missingHofs
        ]);
    }

    /**
     * Scenario 2: Confirm and add missing Family Members from t_its_data to users.
     */
    public function confirmFmFromItsData()
    {
        // Fetch ITS numbers for Family Members (FMs) that are in t_its_data but missing in users
        $missingFms = DB::table('t_its_data')
            ->leftJoin('users', 't_its_data.its', '=', 'users.its')
            ->whereNull('users.its') // Ensure the ITS is not present in users
            ->select('t_its_data.*') // Select all columns from t_its_data
            ->get();

        foreach ($missingFms as $fm) {
            // Skip if there is no HOF for the FM in the users table
            $hof = DB::table('users')
                ->where('its', $fm->hof_its)
                ->where('mumeneen_type', 'HOF')
                ->first();

            if (!$hof) {
                continue; // Skip if no HOF is found
            }

            // Insert the new FM into the users table
            DB::table('users')->insert([
                'username' => $fm->its, // ITS as username
                'role' => 'mumeneen', // Default role for members
                'name' => $fm->name, // Name from t_its_data
                'email' => $fm->email ?? null, // Email if available
                'jamiat_id' => $hof->jamiat_id, // Inherit from HOF
                'family_id' => $hof->family_id, // Inherit from HOF
                'mobile' => $fm->mobile ?? null, // Mobile number
                'its' => $fm->its, // ITS ID
                'hof_its' => $fm->hof_its, // HOF ITS
                'its_family_id' => $fm->its_family_id, // ITS Family ID from t_its_data
                'folio_no' => $hof->folio_no, // Folio number from HOF
                'mumeneen_type' => 'FM', // Family Member
                'title' => in_array($fm->title, ['Shaikh', 'Mulla']) ? $fm->title : null, // Validate title
                'gender' => $fm->gender ?? null, // Gender if available
                'age' => $fm->age ?? null, // Age if available
                'building' => $fm->building ?? null, // Building if available
                'status' => $hof->status, // Status from HOF
                'thali_status' => $hof->thali_status, // Thali status from HOF
                'otp' => null, // Default value
                'expires_at' => null, // Default value
                'email_verified_at' => null, // Default value
                'password' => bcrypt('default_password'), // Default password
                'joint_with' => null, // Default value
                'photo_id' => null, // Default value
                'sector_access_id' => null, // Default value
                'sub_sector_access_id' => null, // Default value
                'sector_id' => $hof->sector_id ?? null, // Inherit from HOF
                'sub_sector_id' => $hof->sub_sector_id ?? null, // Inherit from HOF
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Missing Family Members have been added successfully!']);
    }
    
    /**
     * Scenario 3: Detect HOF present in users but not in t_its_data.
     */
    public function detectInvalidHofInUsers()
    {
        $invalidHofs = DB::table('users')
            ->select('users.its', 'users.name')
            ->leftJoin('t_its_data', 'users.its', '=', 't_its_data.its')
            ->whereNull('t_its_data.its')
            ->whereColumn('users.its', 'users.hof_its')
            ->get();

        return response()->json([
            'message' => 'Invalid HOFs detected in users.',
            'data' => $invalidHofs
        ]);
    }

    /**
     * Scenario 4: Remove FMs in users table that are not in t_its_data.
     */
    public function removeFmNotInItsData()
    {
        $fmsToRemove = DB::table('users')
            ->select('users.its', 'users.name')
            ->leftJoin('t_its_data', 'users.its', '=', 't_its_data.its')
            ->whereNull('t_its_data.its')
            ->where('users.mumeneen_type', 'FM')
            ->get();

        foreach ($fmsToRemove as $fm) {
            DB::table('users')->where('its', $fm->its)->delete();
        }

        return response()->json([
            'message' => 'Family Members not present in t_its_data have been removed.',
            'data' => $fmsToRemove
        ]);
    }

    /**
     * Scenario 5: Detect role mismatches - HOF marked as FM in users.
     */
    public function detectHofMarkedAsFmInUsers()
    {
        $roleMismatches = DB::table('users')
            ->join('t_its_data', 'users.its', '=', 't_its_data.its')
            ->where('users.mumeneen_type', 'FM') // Check mumeneen_type in users
            ->whereColumn('t_its_data.its', 't_its_data.hof_its') // Check HOF in t_its_data
            ->select('users.its', 'users.name', 'users.mumeneen_type as current_type', 't_its_data.hof_its')
            ->get();
    
        return response()->json([
            'message' => 'HOF marked as FM in users detected.',
            'data' => $roleMismatches
        ]);
    }

    /**
     * Scenario 5: Confirm role update - Mark FM in users as HOF.
     */
    public function confirmHofRoleUpdate(Request $request)
    {
        $validated = $request->validate([
            'its_list' => 'required|array',
            'its_list.*.its' => 'required|string',
        ]);
    
        foreach ($validated['its_list'] as $record) {
            DB::table('users')->where('its', $record['its'])->update(['mumeneen_type' => 'HOF']);
        }
    
        return response()->json(['message' => 'Mumeneen type updated to HOF successfully!']);
    }

    /**
     * Scenario 6: Detect role mismatches - HOF marked as HOF in users but FM in t_its_data.
     */
    public function detectHofMarkedAsFmInItsData()
    {
        $roleMismatches = DB::table('users')
            ->join('t_its_data', 'users.its', '=', 't_its_data.its')
            ->where('users.mumeneen_type', 'HOF') // Check HOF in users
            ->whereColumn('t_its_data.hof_its', '!=', 't_its_data.its') // Check FM in t_its_data
            ->select('users.its', 'users.name', 'users.mumeneen_type as current_type', 't_its_data.hof_its')
            ->get();
    
        return response()->json([
            'message' => 'HOF in users but marked as FM in t_its_data detected.',
            'data' => $roleMismatches
        ]);
    }

    /**
     * Scenario 6: Confirm role update - Mark HOF in users as FM.
     */
    public function confirmFmRoleUpdate(Request $request)
    {
        $validated = $request->validate([
            'its_list' => 'required|array',
            'its_list.*.its' => 'required|string',
        ]);
    
        foreach ($validated['its_list'] as $record) {
            DB::table('users')->where('its', $record['its'])->update(['mumeneen_type' => 'FM']);
        }
    
        return response()->json(['message' => 'Mumeneen type updated to FM successfully!']);
    }

    /**
     * Consolidated Sync Function: Runs all scenarios sequentially.
     */
    public function consolidatedSync()
    {
        $missingHofs = $this->detectMissingHofInUsers();
        $invalidHofs = $this->detectInvalidHofInUsers();
        $fmsRemoved = $this->removeFmNotInItsData();
        $fixedHofRoles = $this->detectHofMarkedAsFmInUsers();
        $fixedFmRoles = $this->detectHofMarkedAsFmInItsData();

        return response()->json([
            'status' => 'Sync completed successfully!',
            'results' => [
                'missing_hofs' => $missingHofs->original['data'],
                'invalid_hofs' => $invalidHofs->original['data'],
                'fms_removed' => $fmsRemoved->original['data'],
                'fixed_hof_roles' => $fixedHofRoles->original['data'],
                'fixed_fm_roles' => $fixedFmRoles->original['data'],
            ],
        ]);
    }
}