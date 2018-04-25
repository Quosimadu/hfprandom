<?php
/**
 * Created by PhpStorm.
 * User: paul
 * Date: 14/02/2018
 * Time: 21:36
 */

namespace App\Models\Accounting;


class InvoicePosition
{

    public $text;
    public $quantity;
    public $accountingCoding;
    public $price;
    public $priceVat;
    public $vatClassification;
    public $note;
    public $costCenter;
}