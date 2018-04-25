<? xml version = "1.0" encoding = "Windows-1250"?>
<dat:dataPack version="2.0" id="int002" ico="12345678" application="StwTest" note="Import Interních dokladů"
              xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
              xmlns:int="http://www.stormware.cz/schema/version_2/intDoc.xsd"
              xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd">


    @foreach ($invoices as $invoice)
        <dat:dataPackItem version="2.0" id="INT001">
            <!-- interní doklad s položkami -->
            <int:intDoc version="2.0">
                <int:intDocHeader>
                    <int:date>{{ $invoice->documentDate }}2014-10-15</int:date>
                    <int:dateTax>{{ $invoice->taxDate }}2014-10-15</int:dateTax>
                    <int:dateAccounting>{{ $invoice->accountingDate }}2014-10-28</int:dateAccounting>
                    <int:accounting>
                        <typ:ids>{{ $invoice->accountingCoding }}PoplAir</typ:ids>
                    </int:accounting>
                    <int:classificationVAT>
                        <typ:classificationVATType>none
                        </typ:classificationVATType>
                    </int:classificationVAT>
                    <int:text>{{ $invoice->text }}HMRCH2NN5J, Provize AirBnB, 3n, Olesya Dudenkova</int:text>
                    <!--adresa bez vazby na program POHODA-->
                    <int:partnerIdentity>
                        <typ:address>
                            <typ:name>{{ $invoicePartner->name }}AirBnB itself</typ:name>
                            <typ:city>{{ $invoicePartner->city }}</typ:city>
                            <typ:street>{{ $invoicePartner->street }}</typ:street>
                            <typ:zip>{{ $invoicePartner->postalCode }}</typ:zip>
                        </typ:address>
                    </int:partnerIdentity>
                    <int:centre>
                        <typ:ids>{{ $invoice->costCenter }}NP303</typ:ids>
                    </int:centre>
                    <int:note>{{ $invoiceGlobalData->note }}Načteno z XML.</int:note>
                    <int:intNote>{{ $invoiceGlobalData->internalNote }}Import Interního dokladu s položkama.
                    </int:intNote>
                </int:intDocHeader>
                @if (count($invoice->positions) > 0)
                    <int:intDocDetail>
                    @foreach ($invoice->positions as $invoicePosition)
                        <!--textova polozka-->
                            <int:intDocItem>
                                <int:text>{{ $invoicePosition->text }}HMRCH2NN5J, Provize AirBnB, 3n, Olesya Dudenkova</int:text>
                                <int:quantity>{{ $invoicePosition->quantity }}1</int:quantity>
                                <int:rateVAT>{{ $invoicePosition->vatClassification }}none</int:rateVAT>
                                <int:homeCurrency>
                                    <typ:unitPrice>{{ $invoicePosition->price }}600</typ:unitPrice>
                                    <typ:priceVAT>{{ $invoicePosition->priceVat }}0</typ:priceVAT>
                                </int:homeCurrency>
                                <int:note>{{ $invoicePosition->note }}Provize AirBnB</int:note>
                                <int:accounting>
                                    <typ:ids>{{ $invoicePosition->accountingCoding }}PoplAir</typ:ids>
                                </int:accounting>
                                <int:centre>
                                    <typ:ids>{{ $invoicePosition->costCenter }}NP303</typ:ids>
                                </int:centre>
                            </int:intDocItem>
                        @endforeach
                    </int:intDocDetail>
                @else
                    <int:intDocSummary>
                        <int:homeCurrency>
                            <typ:priceNone>{{ $invoice->price }}548</typ:priceNone>
                        </int:homeCurrency>
                    </int:intDocSummary>
                @endif

            </int:intDoc>
        </dat:dataPackItem>
    @endforeach
</dat:dataPack>
