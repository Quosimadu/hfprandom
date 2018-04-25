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

    /** @var  int harcode to 997 */
    public $symVar;

    public $type;

    public $vatClassification;

    /** @var  string  commitment / receivable **/
    public $invoiceType;

    public $accountingDate;
    public $accountingCoding;
    public $text;
    public $costCenter;
    public $note;
    public $internalNote;
    public $price;
    public $partner;

    /**
     * @var array
     */
    public $positions;

}