<?php
/**
 * Created by PhpStorm.
 * User: paul
 * Date: 14/02/2018
 * Time: 21:26
 */

namespace App\Models\Accounting;


class Invoice
{
    public $taxDate;
    public $documentDate;
    public $accountingDate;
    public $accountingCoding;
    public $vatClassification;
    public $text;
    public $costCenter;
    public $note;
    public $internalNote;
    public $price;

    /**
     * @var array
     */
    public $positions;


}