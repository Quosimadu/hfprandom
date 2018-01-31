<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use http\Exception\InvalidArgumentException;
use Illuminate\Http\Request;
use PHPExcel;
use PHPExcel_IOFactory;

class CsvController extends Controller {

    public $mainAccounts = ['11000' => 'Zsofi', '11001' => 'Walter', '11002' => 'Paul'];

    public $recordTypes=['Payout', 'Reservation', 'Resolution Adjustment'];
    public $accountUnknown='90000';
    public $accountReservation='25000';
    public $accountCleaningFee='86000';
    public $accountPortalFee='87000';

    public $listings = [
        'Center+balcony+walk from station' => 'O57',
        'Modern loft studio @ center'      => 'N404',
        'Tiny, cozy studio in the center'  => 'K9',
        'Modern apartment @center'         => 'B7',
        'Tiny Studio+Balcony @ center'     => 'H42',
        'Lovely studio in the heart of Prague\'s old town'     => 'V14',
        'Large 2-Bedroom Apartment in Center Vinohrady'     => 'K10',
        'Modern loft studio at center'     => 'N303',
        'Modern loft in center'     => 'N403',
        'Jubilee synagogue, walk from center'     => 'J7',
        'Spacious 2-bedrooms @ Dancing House / center'     => 'R1',
        'Bright apartment in Center Vinohrady'     => 'M91',
        'Huge top floor apt. in center'     => 'N13',
        'Spacious, Luxury Top-Floor Loft Apartment @Center'     => 'N603',
        'Large apartment in Center Vinohrady'     => 'M92',
        'Cozy top floor apartment in the very center'     => 'N14',
    ];

    public $bankAccounts = [
        '5924' => 15004,
        '5926' => 15005,
        '5927' => 15006,
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

        $inputFileType = 'CSV';
        $inputFileName = $request->file('csv')->getRealPath();

        $objReader = PHPExcel_IOFactory::createReader($inputFileType);
        $objPHPExcel = $objReader->load($inputFileName);

        $this->worksheet = $objPHPExcel->getActiveSheet();
        $result = [];
        $resultRow = 1;
        $modifyOutputFilename = false;
        foreach ($this->worksheet->getRowIterator() as $row) {
            $rowNumber = $row->getRowIndex();
            //ignore first 2 rows
            if ($rowNumber < 3) {
                continue;
            }

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false); // Loop all cells, even if it is not set

            $resultRow++;
            $result[$resultRow]['A'] = $this->getFormatedDate($rowNumber);
            $result[$resultRow]['B'] = $request->input('account');
            $result[$resultRow]['E'] = $this->getVatValue($rowNumber);


            $listing = $this->worksheet->getCell($this->columns['listing'] . $rowNumber)->getValue();
            $result[$resultRow]['G'] = '';
            $listingsException = false;

            if (!empty($this->listings[$listing])) {
                $result[$resultRow]['G'] = $this->listings[$listing];
            } elseif (!empty($listing)) {
                $listingsException = true;
                $modifyOutputFilename = true;
            }

            $details = $this->parseAmount($rowNumber);
            if (!empty($details)) {
                foreach ($details as $no => $detail) {
                    if ($no > 0) {
                        $resultRow++;
                        $result[$resultRow] = $result[$resultRow - 1];  //copy previous row if rows must be splitted
                    }
                    $result[$resultRow]['C'] = $listingsException ? $this->accountUnknown: $detail['account'];
                    $result[$resultRow]['D'] = $detail['amount'];
                    $result[$resultRow]['F'] = $detail['reference'];
                    if (!empty($details['undefined_type'])) {
                        $modifyOutputFilename = true;
                    }
                }
            }
        }

        $filename = $request->file('csv')->getClientOriginalName();
        if ($modifyOutputFilename) {
            $filename = str_replace('.' . $request->file('csv')->getClientOriginalExtension(), '-attention.' . $request->file('csv')->getClientOriginalExtension(), $filename);
        }

        $objPHPExcel = new PHPExcel();
        $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
        $objWriter->setEnclosure('"');
        $objWriter->setDelimiter(',');
        $objWriter->setSheetIndex(0);   // Select which sheet.

        $worksheet = $objPHPExcel->getActiveSheet();
        $worksheet->setCellValue('A1', 'Date');
        $worksheet->setCellValue('B1', 'Main account');
        $worksheet->setCellValue('C1', 'Account');
        $worksheet->setCellValue('D1', 'Amount');
        $worksheet->setCellValue('E1', 'VAT');
        $worksheet->setCellValue('F1', 'Reference');
        $worksheet->setCellValue('G1', 'Cost Center');

        if (!empty($result)) {
            foreach ($result as $row => $cells) {
                foreach ($cells as $cell => $value) {
                    $worksheet->setCellValue($cell . $row, $value);
                }
            }
        }

        $objWriter->save(storage_path($filename));

        return response()->download(storage_path($filename), $filename);
    }

    private function getFormatedDate($row)
    {
        $date = $this->worksheet->getCell($this->columns['date'] . $row)->getValue();
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


    private function parseAmount($row)
    {
        $results = [];
        $type = $this->worksheet->getCell($this->columns['type'] . $row)->getValue();

        switch ($type) {
            case 'Reservation':
                $reference = $this->worksheet->getCell($this->columns['confirmation_code'] . $row)->getValue() . ', ' . $this->worksheet->getCell($this->columns['nights'] . $row)->getValue() . 'n, ' . $this->worksheet->getCell($this->columns['guest'] . $row)->getValue();
                $results[] = [
                    'amount'    => $this->worksheet->getCell($this->columns['amount'] . $row)->getValue() - $this->worksheet->getCell($this->columns['host_fee'] . $row)->getValue() - $this->worksheet->getCell($this->columns['cleaning_fee'] . $row)->getValue(),
                    'account'   => $this->accountReservation,
                    'reference' => $reference,
                ];
                $results[] = [
                    'amount'    => $this->worksheet->getCell($this->columns['host_fee'] . $row)->getValue(),
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
                $account = '';

                foreach ($this->bankAccounts as $k => $v) {
                    preg_match('/Transfer\sto\sAccount\s\*\*\*\*\*([0-9]{4})\s\(CZK\)/', $details, $matches);
                    if (!empty($matches)) {
                        $account = $v;
                        break;
                    }
                }

                $results[] = [
                    'amount'    => $this->worksheet->getCell($this->columns['paid_out'] . $row)->getValue(),
                    'account'   => $account,
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
                    'amount'    => $this->worksheet->getCell($this->columns['amount'] . $row)->getValue(),
                    'account'   => $this->accountUnknown,
                    'reference' => $this->worksheet->getCell($this->columns['details'] . $row)->getValue(),
                    'undefined_type' => true,
                ];
        }

        return $results;
    }

}
