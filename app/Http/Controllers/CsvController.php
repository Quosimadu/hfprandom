<?php

namespace App\Http\Controllers;

use App\Models\Accounting\Address;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\InvoicePosition;
use Carbon\Carbon;
use http\Exception\InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PHPExcel;
use PHPExcel_IOFactory;

class CsvController extends Controller {

    const VAT = 21;

    public $mainAccounts = ['1' => 'Zsofi', '2' => 'Walter', '3' => 'Paul'];

    public $recordTypes = ['Payout', 'Reservation', 'Resolution Adjustment'];
    public $accountUnknown = '90000';
    public $accountReservation = 'AiRBnB';
    public $accountCleaningFee = 'AirClean';
    public $accountPortalFee = 'PoplAir';

    public $listings = [
        'Center+balcony+walk from station'                  => 'O57',
        'Modern loft studio @ center'                       => 'N404',
        'Tiny, cozy studio in the center'                   => 'K9',
        'Modern apartment @center'                          => 'B7',
        'Tiny Studio+Balcony @ center'                      => 'H42',
        'Lovely studio in the heart of Prague\'s old town'  => 'V14',
        'Large 2-Bedroom Apartment in Center Vinohrady'     => 'K10',
        'Big 2-story maisonette apartment close to center'  => 'T13',
        'Modern loft studio at center'                      => 'N303',
        'Modern loft in center'                             => 'N403',
        'Jubilee synagogue, walk from center'               => 'J7',
        'Spacious 2-bedrooms @ Dancing House / center'      => 'R1',
        'Bright apartment in Center Vinohrady'              => 'M91',
        'Huge top floor apt. in center'                     => 'N13',
        'Spacious, Luxury Top-Floor Loft Apartment @Center' => 'N603',
        'Large apartment in Center Vinohrady'               => 'M92',
        'Cozy top floor apartment in the very center'       => 'N14',
        '2 Huge Adjoining Apartments, up to 27 Guests'      => [
            'M91' => 40,
            'M92' => 60,
        ],
    ];

    public $bankAccounts = [
        '5924' => 15004,
        '5926' => 15005,
        '5927' => 15006,
        '5916' => 33333,
    ];

    private $columns = [
        'date'              => 'A',
        'type'              => 'B',
        'confirmation_code' => 'C',
        'start_date'        => 'D',
        'nights'            => 'E',
        'guest'             => 'F',
        'listing'           => 'G',
        'details'           => 'H',
        'reference'         => 'I',
        'currency'          => 'J',
        'amount'            => 'K',
        'paid_out'          => 'L',
        'host_fee'          => 'M',
        'cleaning_fee'      => 'N',
    ];

    private $worksheet;

    private $invoices;
    private $modifyOutputFilename = false;

    public function index()
    {
        return view('csv/form', [
            'mainAccounts' => $this->mainAccounts,
        ]);
    }

    public function reformat(Request $request)
    {
        $validatedData = $request->validate([
            'csv' => 'required|file',
        ]);

        $invoiceGlobalData = new Invoice();
        $invoiceGlobalData->note = 'XML Import';
        $invoiceGlobalData->internalNote = 'XML imported as outgoing invoice';

        $inputFileType = 'CSV';
        $inputFileName = $request->file('csv')->getRealPath();

        $objReader = PHPExcel_IOFactory::createReader($inputFileType);
        $objPHPExcel = $objReader->load($inputFileName);

        $this->worksheet = $objPHPExcel->getActiveSheet();

        foreach ($this->worksheet->getRowIterator() as $row) {
            $rowNumber = $row->getRowIndex();
            //ignore first 2 rows
            if ($rowNumber < 3) {
                continue;
            }

            if ($this->worksheet->getCell($this->columns['type'] . $rowNumber)->getValue() != 'Reservation') {
                continue;
            }

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false); // Loop all cells, even if it is not set

            $this->addAirbnbInvoice($rowNumber);
            $this->addCustomerInvoice($rowNumber);
        }

        $xml = view('csv.xml_invoice', [
            'invoices'          => $this->invoices,
            'invoiceGlobalData' => $invoiceGlobalData,
        ])->render();

        $filename = $request->file('csv')->getClientOriginalName();
        $filename = str_replace('.' . $request->file('csv')->getClientOriginalExtension(), '', $filename);

        if ($this->modifyOutputFilename) {
            $filename .= '-attention';
        }
        $filename .= '.xml';

        Storage::put($filename, $xml);

        return response()->download(storage_path('/app/' . $filename), $filename);
    }

    private function addAirbnbInvoice($row)
    {
        $invoice = new Invoice();
        $invoice->type = 'commitment';
        $invoice->vatClassification = 'none';
        $invoice->documentDate = $this->getDate($this->columns['date'] . $row);
        $invoice->taxDate = $invoice->accountingDate = $this->getDate($this->columns['start_date'] . $row);
        $invoice->accountingCoding = $this->accountPortalFee . request('account');
        $invoice->text = $this->getAirbnbInvoiceText($row);

        $invoicePartner = new Address();
        $invoicePartner->name = 'AirBnB, Inc.';

        $invoice->partner = $invoicePartner;

        $listing = $this->getListing($row);

        if (!$listing['split']) {
            $invoice->costCenter = $listing['cost_center'];
            $invoice->positions[] = $this->getHostFeePosition($row, $listing['cost_center']);
            $this->invoices[] = $invoice;
        } else {
            foreach ($listing['cost_center'] as $costCenter => $splitPercent) {
                $invoice->costCenter = $costCenter;
                $invoice->positions[] = $this->getHostFeePosition($row, $costCenter, $splitPercent);

                $this->invoices[] = $invoice;

                $invoice = clone $invoice;
                $invoice->positions = [];
            }
        }
    }

    private function addCustomerInvoice($row)
    {
        $invoice = new Invoice();
        $invoice->type = 'receivable';
        $invoice->vatClassification = 'nonSubsume';
        $invoice->documentDate = $this->getDate($this->columns['date'] . $row);
        $invoice->taxDate = $invoice->accountingDate = $this->getDate($this->columns['start_date'] . $row);
        $invoice->accountingCoding = $this->accountReservation . request('account');
        $invoice->text = $this->getInvoiceText($row);

        $invoicePartner = new Address();
        $invoicePartner->name = $this->worksheet->getCell($this->columns['guest'] . $row)->getValue();

        $invoice->partner = $invoicePartner;

        $listing = $this->getListing($row);

        if (!$listing['split']) {
            $invoice->costCenter = $listing['cost_center'];
            $invoice->positions[] = $this->getReservationPosition($row, $listing['cost_center']);
            $invoice->positions[] = $this->getCleaningPosition($row, $listing['cost_center']);
            $this->invoices[] = $invoice;
        } else {
            foreach ($listing['cost_center'] as $costCenter => $splitPercent) {
                $invoice->costCenter = $costCenter;
                $invoice->positions[] = $this->getReservationPosition($row, $costCenter, $splitPercent);
                $invoice->positions[] = $this->getCleaningPosition($row, $costCenter, $splitPercent);

                $this->invoices[] = $invoice;

                $invoice = clone $invoice;
                $invoice->positions = [];
            }
        }
    }

    private function getListing($row)
    {
        $listing = $this->worksheet->getCell($this->columns['listing'] . $row)->getValue();
        $listingsException = false;

        if (!empty($this->listings[$listing])) {
            return [
                'cost_center' => $this->listings[$listing],
                'exception'   => false,
                'split'       => is_array($this->listings[$listing]),
            ];
        }

        $this->modifyOutputFilename = true;

        return [
            'cost_center' => null,
            'exception'   => true,
            'split'       => false,
        ];
    }

    private function getDate($cell)
    {
        $date = $this->worksheet->getCell($cell)->getValue();
        try {
            $date = Carbon::createFromFormat('m/d/Y', $date);

            return $date->toDateString();
        } catch (\Exception $e) {

        }

        return $date;
    }

    private function vatIncluded($row)
    {
        $nights = $this->worksheet->getCell($this->columns['nights'] . $row)->getValue();

        return $nights > 2;
    }

    private function getInvoiceText($row)
    {
        return $this->worksheet->getCell($this->columns['confirmation_code'] . $row)->getValue() . ', ' . $this->worksheet->getCell($this->columns['nights'] . $row)->getValue() . 'n, ' . $this->worksheet->getCell($this->columns['guest'] . $row)->getValue();
    }

    private function getAirbnbInvoiceText($row)
    {
        return $this->worksheet->getCell($this->columns['confirmation_code'] . $row)->getValue() . ', Provize AirBnB, ' . $this->worksheet->getCell($this->columns['nights'] . $row)->getValue() . 'n, ' . $this->worksheet->getCell($this->columns['guest'] . $row)->getValue();
    }

    private function getHostFeePosition($row, $costCenter, $splitPercent = null)
    {
        $position = new InvoicePosition();
        $position->text = $this->getAirbnbInvoiceText($row);
        $position->quantity = 1;
        $position->vatClassification = 'none';
        $position->accountingCoding = 'PoplAir';
        $price = $this->getPrice($this->columns['host_fee'] . $row, $splitPercent, false);
        $position->price = $price['price'];
        $position->priceVat = $price['vat'];
        $position->note = 'Provize AirBnB';
        $position->costCenter = $costCenter;

        return $position;
    }

    private function getReservationPosition($row, $costCenter, $splitPercent = null)
    {
        $hasVat = $this->vatIncluded($row);
        $position = new InvoicePosition();
        $position->text = $this->getInvoiceText($row);
        $position->quantity = 1;
        $position->vatClassification = $hasVat ? 'nonSubsume' : 'none';
        $position->accountingCoding = $this->accountReservation . request('account');
        $price = $this->getPrice($this->columns['amount'] . $row, $splitPercent, $hasVat);
        $position->price = $price['price'];
        $position->priceVat = $price['vat'];
        $position->note = $this->worksheet->getCell($this->columns['confirmation_code'] . $row)->getValue();
        $position->costCenter = $costCenter;

        return $position;
    }

    private function getCleaningPosition($row, $costCenter, $splitPercent = null)
    {
        $hasVat = $this->vatIncluded($row);
        $position = new InvoicePosition();
        $position->text = $this->getInvoiceText($row);
        $position->quantity = 1;
        $position->vatClassification = 'none';
        $position->accountingCoding = $this->accountCleaningFee . request('account');
        $position->accountingCoding = $this->accountReservation . request('account');
        $price = $this->getPrice($this->columns['cleaning_fee'] . $row, $splitPercent, $hasVat);
        $position->price = $price['price'];
        $position->priceVat = $price['vat'];
        $position->note = $this->worksheet->getCell($this->columns['confirmation_code'] . $row)->getValue();
        $position->costCenter = $costCenter;

        return $position;
    }

    private function getPrice($cell, $splitPercent = null, $exludeVat = false)
    {
        $price = (float)$this->worksheet->getCell($cell)->getValue();

        if (!empty($splitPercent)) {
            $price = ($price * $splitPercent) / 100;
        }

        if ($exludeVat) {
            $priceWithoutVat = round($price / (1 + self::VAT / 100), 2);
            $vat = $price - $priceWithoutVat;
        } else {
            $priceWithoutVat = $price;
            $vat = 0;
        }

        return [
            'price' => number_format($priceWithoutVat, 2, '.', ''),
            'vat'   => (empty($vat) ? 0 : number_format($vat, 2, '.', '')),
        ];
    }

    private function parseAmount($row)
    {
        $results = [];
        $type = $this->worksheet->getCell($this->columns['type'] . $row)->getValue();

        switch ($type) {
            case 'Reservation':
                $reference = $this->worksheet->getCell($this->columns['confirmation_code'] . $row)->getValue() . ', ' . $this->worksheet->getCell($this->columns['nights'] . $row)->getValue() . 'n, ' . $this->worksheet->getCell($this->columns['guest'] . $row)->getValue();
                $results[] = [
                    'amount'    => $this->worksheet->getCell($this->columns['amount'] . $row)->getValue() + $this->worksheet->getCell($this->columns['host_fee'] . $row)->getValue() - $this->worksheet->getCell($this->columns['cleaning_fee'] . $row)->getValue(),
                    'account'   => $this->accountReservation,
                    'reference' => $reference,
                ];
                $results[] = [
                    'amount'    => -$this->worksheet->getCell($this->columns['host_fee'] . $row)->getValue(),
                    'account'   => $this->accountPortalFee,
                    'reference' => $reference,
                ];
                $results[] = [
                    'amount'    => $this->worksheet->getCell($this->columns['cleaning_fee'] . $row)->getValue(),
                    'account'   => $this->accountCleaningFee,
                    'reference' => $reference,
                ];
                break;
            case 'Payout':
                $details = $this->worksheet->getCell($this->columns['details'] . $row)->getValue();

                if (preg_match('/Transfer\sto\sAccount\s\*\*\*\*\*([0-9]{4})\s\(CZK\)/', $details, $matches) === false) {
                    break;
                }
                if (empty($matches) || !isset($this->bankAccounts[$matches[1]])) {
                    break;
                }

                $results[] = [
                    'amount'    => $this->worksheet->getCell($this->columns['paid_out'] . $row)->getValue(),
                    'account'   => $this->bankAccounts[$matches[1]],
                    'reference' => '',
                ];
                break;
            case 'Resolution Adjustment':
                $results[] = [
                    'amount'    => $this->worksheet->getCell($this->columns['amount'] . $row)->getValue(),
                    'account'   => $this->accountUnknown,
                    'reference' => $this->worksheet->getCell($this->columns['details'] . $row)->getValue(),
                ];
                break;

            default:
                $results[] = [
                    'amount'         => $this->worksheet->getCell($this->columns['amount'] . $row)->getValue(),
                    'account'        => $this->accountUnknown,
                    'reference'      => $this->worksheet->getCell($this->columns['details'] . $row)->getValue(),
                    'undefined_type' => true,
                ];
        }

        return $results;
    }

}
