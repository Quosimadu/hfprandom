<?php

namespace App\Http\Controllers;

use App\Models\Accounting\Address;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\InvoicePosition;
use Carbon\Carbon;
use http\Exception\InvalidArgumentException;
use Illuminate\Http\Request;
use PHPExcel;
use PHPExcel_IOFactory;

class CsvController extends Controller {

    public $mainAccounts = ['11000' => 'Zsofi', '11001' => 'Walter', '11002' => 'Paul'];

    public $recordTypes = ['Payout', 'Reservation', 'Resolution Adjustment'];
    public $accountUnknown = '90000';
    public $accountReservation = '25000';
    public $accountCleaningFee = '86000';
    public $accountPortalFee = '87000';

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

    private $invoicesExport;

    private $airbnbInvoices;
    private $customerInvoices;
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

        $this->invoicesExport = [];

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

        $airbnbInvoice = view('csv.xml_export_airbnb_invoice', [
            'invoices'          => $this->airbnbInvoices,
            'invoiceGlobalData' => $invoiceGlobalData,
        ])->render();
        dd($airbnbInvoice);

        $filename = $request->file('csv')->getClientOriginalName();
        $filename = str_replace('.' . $request->file('csv')->getClientOriginalExtension(), '', $filename);

        if ($this->modifyOutputFilename) {
            $filename .= '-attention';
        }
        $filename .= '.zip';

        return response()->download(storage_path($filename), $filename);
    }

    private function addAirbnbInvoice($row)
    {
        $invoice = new Invoice();
        $invoice->documentDate = $this->getDate($this->columns['date'] . $row);
        $invoice->taxDate = $invoice->accountingDate = $this->getDate($this->columns['start_date'] . $row);
        $invoice->accountingCoding = request('account');
        $invoice->text = $this->getInvoiceText($row);

        $invoicePartner = new Address();
        $invoicePartner->name = 'AirBnB, Inc.';

        $invoice->partner = $invoicePartner;

        $listing = $this->getListing($row);

        if (!$listing['split']) {
            $invoice->costCenter = $listing['cost_center'];
            $invoice->positions[] = $this->getHostFeePosition($row, $listing['cost_center']);
            $this->airbnbInvoices[] = $invoice;
        } else {
            foreach ($listing as $costCenter => $splitPercent) {
                $invoice->costCenter = $costCenter;
                $invoice->positions[] = $this->getHostFeePosition($row, $costCenter, $splitPercent);

                $this->airbnbInvoices[] = $invoice;
            }
        }
    }

    private function addCustomerInvoice($row)
    {
        $invoice = new Invoice();
        $invoice->documentDate = $this->getDate($this->columns['date'] . $row);
        $invoice->taxDate = $invoice->accountingDate = $this->getDate($this->columns['start_date'] . $row);
        $invoice->accountingCoding = request('account');
        $invoice->text = $this->getInvoiceText($row);

        $invoicePartner = new Address();
        $invoicePartner->name = $this->worksheet->getCell($this->columns['guest'] . $row)->getValue();

        $invoice->partner = $invoicePartner;

        $listing = $this->getListing($row);

        if (!$listing['split']) {
            $invoice->costCenter = $listing['cost_center'];
            $invoice->positions[] = $this->getAmountPosition($row, $listing['cost_center']);
            $invoice->positions[] = $this->getCleaningPosition($row, $listing['cost_center']);
            $this->customerInvoices[] = $invoice;
        } else {
            foreach ($listing as $costCenter => $splitPercent) {
                $invoice->costCenter = $costCenter;
                $invoice->positions[] = $this->getAmountPosition($row, $costCenter, $splitPercent);
                $invoice->positions[] = $this->getCleaningPosition($row, $costCenter, $splitPercent);

                $this->customerInvoices[] = $invoice;
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

    private function getVatValue($row)
    {
        $nights = $this->worksheet->getCell($this->columns['nights'] . $row)->getValue();

        return $nights > 2 ? '0' : '1';
    }

    private function getInvoiceText($row)
    {
        return $this->worksheet->getCell($this->columns['confirmation_code'] . $row)->getValue() . ', ' . $this->worksheet->getCell($this->columns['nights'] . $row)->getValue() . 'n, ' . $this->worksheet->getCell($this->columns['guest'] . $row)->getValue();
    }

    private function getHostFeePosition($row, $costCenter, $splitPercent = null)
    {
        $position = new InvoicePosition();
        $position->text = $this->getInvoiceText($row);
        $position->quantity = 1;
        $position->vatClassification = 'none';
        $position->accountingCoding = 'PoplAir';
        $position->price = $this->getPrice($this->columns['host_fee'] . $row, $splitPercent);
        $position->priceVat = '0';
        $position->note = 'Provize AirBnB';
        $position->costCenter = $costCenter;

        return $position;
    }

    private function getAmountPosition($row, $costCenter, $splitPercent = null)
    {
        $position = new InvoicePosition();
        $position->text = $this->getInvoiceText($row);
        $position->quantity = 1;
        $position->vatClassification = 'none';
        $position->accountingCoding = request('account');
        $position->price = $this->getPrice($this->columns['amount'] . $row, $splitPercent);
        $position->priceVat = '0';
        $position->note = $this->worksheet->getCell($this->columns['confirmation_code'] . $row)->getValue();
        $position->costCenter = $costCenter;

        return $position;
    }

    private function getCleaningPosition($row, $costCenter, $splitPercent = null)
    {
        $position = new InvoicePosition();
        $position->text = $this->getInvoiceText($row);
        $position->quantity = 1;
        $position->vatClassification = 'none';
        $position->accountingCoding = request('account');
        $position->price = $this->getPrice($this->columns['cleaning_fee'] . $row, $splitPercent);
        $position->priceVat = '0';
        $position->note = $this->worksheet->getCell($this->columns['confirmation_code'] . $row)->getValue();
        $position->costCenter = $costCenter;

        return $position;
    }

    private function getPrice($cell, $splitPercent = null)
    {
        $price = $this->worksheet->getCell($cell)->getValue();
        if (!empty($splitPercent)) {
            $price = ($price * $splitPercent) / 100;
        }

        return number_format($price, 2, '.', '');
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
