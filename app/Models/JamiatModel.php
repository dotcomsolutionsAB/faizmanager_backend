<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JamiatModel extends Model
{
    //
    protected $table = 't_jamiat';

    protected $fillable = [
        'name', 'mobile', 'email', 'package', 'billing_address', 'billing_contact', 'billing_email', 'billing_phone', 'last_payment_date', 'last_payment_amount', 'payment_due_date', 'validity', 'notes', 'logs'
    ];
}
