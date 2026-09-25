<?php

require_once __DIR__ . '/../../classes/Components/Document/DocumentDataService.php';

use Xentral\Components\Document\DocumentDataService;

/**
 * Standalone Unit Tests for DocumentDataService EN 16931 Calculation Rules.
 */
class DocumentDataServiceTest
{
    private $service;
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        // Mock application core
        $app = new stdClass();
        $this->service = new DocumentDataService($app);
    }

    public function run()
    {
        echo "====================================================\n";
        echo "Running DocumentDataService EN 16931 Arithmetic Tests\n";
        echo "====================================================\n";

        $this->testSingleStandardTaxRate();
        $this->testMixedTaxRates();
        $this->testDiscountsAndRounding();
        $this->testTaxExemptDocument();
        $this->testPrestoredDbNetSums();
        $this->testZeroRatedItems();
        $this->testAlexForumDiscrepancyCase();
        $this->testUnitCodeMapping();
        $this->testXRechnungTemplateRendering();

        echo "----------------------------------------------------\n";
        echo "Tests completed: {$this->passed} passed, {$this->failed} failed.\n";
        echo "====================================================\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert($condition, string $message)
    {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$message}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$message}\n";
        }
    }

    private function assertEquals($expected, $actual, string $message, float $delta = 0.0001)
    {
        $match = is_numeric($expected) && is_numeric($actual)
            ? abs(((float)$expected) - ((float)$actual)) <= $delta
            : $expected === $actual;

        if ($match) {
            $this->passed++;
            echo "  [PASS] {$message}\n";
        } else {
            $this->failed++;
            $expStr = var_export($expected, true);
            $actStr = var_export($actual, true);
            echo "  [FAIL] {$message} (Expected: {$expStr}, Actual: {$actStr})\n";
        }
    }

    /**
     * Scenario 1: Standard 19% invoice with multiple positions.
     */
    public function testSingleStandardTaxRate()
    {
        echo "\nTest 1: Standard 19% Invoice\n";

        $kopf = ['waehrung' => 'EUR', 'angezahlt' => 0];
        $positions = [
            ['id' => 1, 'menge' => 2, 'preis' => 10.00, 'rabatt' => 0, 'steuersatz' => 19.0],
            ['id' => 2, 'menge' => 3, 'preis' => 15.50, 'rabatt' => 0, 'steuersatz' => 19.0],
        ];

        $res = $this->service->calculateDocument('rechnung', $kopf, $positions);

        // BT-131: Line 1 = 20.00, Line 2 = 46.50
        $this->assertEquals(20.00, $res['positionen'][0]['umsatz_netto_gesamt'], 'BT-131 Line 1 net amount');
        $this->assertEquals(46.50, $res['positionen'][1]['umsatz_netto_gesamt'], 'BT-131 Line 2 net amount');

        // BT-106: Sum of lines = 66.50
        $this->assertEquals(66.50, $res['summen']['line_extension_amount'], 'BT-106 LineExtensionAmount');

        // BT-110: Tax 19% on 66.50 = 12.635 -> 12.64
        $this->assertEquals(12.64, $res['summen']['tax_total_amount'], 'BT-110 TaxTotalAmount');

        // BT-112: Tax inclusive = 66.50 + 12.64 = 79.14
        $this->assertEquals(79.14, $res['summen']['tax_inclusive_amount'], 'BT-112 TaxInclusiveAmount');

        // BT-115: Payable = 79.14
        $this->assertEquals(79.14, $res['summen']['payable_amount'], 'BT-115 PayableAmount');

        // Legacy Smarty template check: (umsatz_brutto_gesamt - steuer_gesamt) == LineExtensionAmount
        $legacyLineTotal = $res['summen']['tax_inclusive_amount'] - $res['summen']['tax_total_amount'];
        $this->assertEquals($res['summen']['line_extension_amount'], $legacyLineTotal, 'Smarty (brutto - steuer) == BT-106');

        // PDF summen_nach_steuersatz
        $this->assertEquals(12.64, $res['summen_nach_steuersatz']['19'], 'PDF summen_nach_steuersatz 19%');
    }

    /**
     * Scenario 2: Mixed rates (19% standard, 7% reduced).
     */
    public function testMixedTaxRates()
    {
        echo "\nTest 2: Mixed Tax Rates (19% and 7%)\n";

        $kopf = ['waehrung' => 'EUR', 'angezahlt' => 20.00];
        $positions = [
            ['id' => 1, 'menge' => 1, 'preis' => 100.00, 'rabatt' => 0, 'steuersatz' => 19.0],
            ['id' => 2, 'menge' => 2, 'preis' => 50.00, 'rabatt' => 0, 'steuersatz' => 7.0],
        ];

        $res = $this->service->calculateDocument('rechnung', $kopf, $positions);

        // BT-106: 100.00 + 100.00 = 200.00
        $this->assertEquals(200.00, $res['summen']['line_extension_amount'], 'BT-106 Net sum');

        // Tax breakdown: 19% on 100.00 = 19.00, 7% on 100.00 = 7.00
        $this->assertEquals(26.00, $res['summen']['tax_total_amount'], 'BT-110 Total tax');
        $this->assertEquals(226.00, $res['summen']['tax_inclusive_amount'], 'BT-112 Gross amount');

        // Prepayment 20.00: BT-115 = 206.00
        $this->assertEquals(206.00, $res['summen']['payable_amount'], 'BT-115 Payable after prepayment');

        $this->assertEquals(2, count($res['steuern']), 'Two VAT category groups');
        $this->assertEquals(19.00, $res['summen_nach_steuersatz']['19'], '19% tax breakdown');
        $this->assertEquals(7.00, $res['summen_nach_steuersatz']['7'], '7% tax breakdown');
    }

    /**
     * Scenario 3: Positions with line discount (rabatt) and 3-digit quantity.
     */
    public function testDiscountsAndRounding()
    {
        echo "\nTest 3: Discounts and Fractional Quantities\n";

        $kopf = ['waehrung' => 'EUR'];
        $positions = [
            // 3.5 hrs @ 85.00 EUR with 10% discount: 85 * 0.9 = 76.50 -> 3.5 * 76.50 = 267.75
            ['id' => 1, 'menge' => 3.5, 'preis' => 85.00, 'rabatt' => 10, 'steuersatz' => 19.0],
        ];

        $res = $this->service->calculateDocument('rechnung', $kopf, $positions);

        $this->assertEquals(267.75, $res['positionen'][0]['umsatz_netto_gesamt'], 'BT-131 Discounted net');
        $this->assertEquals(50.87, $res['summen']['tax_total_amount'], 'BT-110 19% of 267.75 = 50.8725 -> 50.87');
        $this->assertEquals(318.62, $res['summen']['tax_inclusive_amount'], 'BT-112 267.75 + 50.87 = 318.62');
    }

    /**
     * Scenario 4: Tax exempt document (ust_befreit).
     */
    public function testTaxExemptDocument()
    {
        echo "\nTest 4: Tax Exempt Document (ust_befreit)\n";

        $kopf = ['waehrung' => 'EUR', 'ust_befreit' => 1];
        $positions = [
            ['id' => 1, 'menge' => 1, 'preis' => 500.00, 'rabatt' => 0, 'steuersatz' => 19.0],
        ];

        $res = $this->service->calculateDocument('rechnung', $kopf, $positions);

        $this->assertEquals(500.00, $res['summen']['line_extension_amount'], 'BT-106 Net');
        $this->assertEquals(0.00, $res['summen']['tax_total_amount'], 'BT-110 Tax is 0');
        $this->assertEquals(500.00, $res['summen']['tax_inclusive_amount'], 'BT-112 Gross equals net');
        $this->assertEquals('E', $res['steuern'][0]['kategorie'], 'Category code is E (Exempt)');
    }

    /**
     * Scenario 5: Pre-stored DB umsatz_netto_gesamt preserves exact line sum.
     */
    public function testPrestoredDbNetSums()
    {
        echo "\nTest 5: Pre-stored DB Net Sums\n";

        $kopf = ['waehrung' => 'EUR'];
        $positions = [
            ['id' => 1, 'menge' => 1, 'preis' => 10.00, 'rabatt' => 0, 'steuersatz' => 19.0, 'umsatz_netto_gesamt' => '10.00'],
            ['id' => 2, 'menge' => 1, 'preis' => 20.00, 'rabatt' => 0, 'steuersatz' => 19.0, 'umsatz_netto_gesamt' => '20.00'],
        ];

        $res = $this->service->calculateDocument('rechnung', $kopf, $positions);

        $this->assertEquals(30.00, $res['summen']['line_extension_amount'], 'BT-106 matches DB sum');
    }

    /**
     * Scenario 6: Zero rated items (Z category).
     */
    public function testZeroRatedItems()
    {
        echo "\nTest 6: Zero Rated Items (Category Z)\n";

        $kopf = ['waehrung' => 'EUR', 'ust_befreit' => 0];
        $positions = [
            ['id' => 1, 'menge' => 1, 'preis' => 100.00, 'rabatt' => 0, 'steuersatz' => 0.0],
        ];

        $res = $this->service->calculateDocument('rechnung', $kopf, $positions);

        $this->assertEquals('Z', $res['steuern'][0]['kategorie'], 'Category code is Z for 0% non-exempt');
    }

    /**
     * Scenario 7: Real-world 1-cent discrepancy test case from Forum post 3407.
     *
     * In legacy OpenXE:
     * Line 1: 3 x 3.33 = 9.99 net -> 19% tax = 1.8981 -> rounded line tax = 1.90, gross = 11.89
     * Line 2: 3 x 3.33 = 9.99 net -> 19% tax = 1.8981 -> rounded line tax = 1.90, gross = 11.89
     * Line 3: 3 x 3.33 = 9.99 net -> 19% tax = 1.8981 -> rounded line tax = 1.90, gross = 11.89
     *
     * If summed line-by-line:
     * Net sum = 29.97.
     * Line tax sum = 1.90 + 1.90 + 1.90 = 5.70.
     * Gross sum = 35.67.
     *
     * BUT in EN 16931 / §14 UStG:
     * Tax must be calculated on aggregated net base:
     * 29.97 * 0.19 = 5.6943 -> 5.69!
     * Correct gross = 29.97 + 5.69 = 35.66.
     *
     * Notice the 1-cent difference between 5.70 and 5.69!
     * Schematron rejects 5.70 because 29.97 * 0.19 != 5.70.
     */
    public function testAlexForumDiscrepancyCase()
    {
        echo "\nTest 7: The Classic 1-Cent Discrepancy (Forum Post 3407)\n";

        $kopf = ['waehrung' => 'EUR'];
        $positions = [
            ['id' => 1, 'menge' => 3, 'preis' => 3.33, 'rabatt' => 0, 'steuersatz' => 19.0],
            ['id' => 2, 'menge' => 3, 'preis' => 3.33, 'rabatt' => 0, 'steuersatz' => 19.0],
            ['id' => 3, 'menge' => 3, 'preis' => 3.33, 'rabatt' => 0, 'steuersatz' => 19.0],
        ];

        $res = $this->service->calculateDocument('rechnung', $kopf, $positions);

        // BT-106 must be exactly 29.97
        $this->assertEquals(29.97, $res['summen']['line_extension_amount'], 'BT-106 Net base is 29.97');

        // Category tax must be round(29.97 * 0.19, 2) = 5.69 (NOT 5.70)
        $this->assertEquals(5.69, $res['summen']['tax_total_amount'], 'BT-110 Tax is 5.69 (EN 16931 category rule)');

        // BT-112 must be 29.97 + 5.69 = 35.66
        $this->assertEquals(35.66, $res['summen']['tax_inclusive_amount'], 'BT-112 Gross is 35.66');

        // In Smarty template: (brutto - tax) = 35.66 - 5.69 = 29.97 == BT-106!
        $smartyNet = round($res['umsatz_brutto_gesamt'] - $res['steuer_gesamt'], 2);
        $this->assertEquals(29.97, $smartyNet, 'Smarty template net formula gives exact 29.97 without rounding error');

        // PDF summen_nach_steuersatz gives 5.69
        $this->assertEquals(5.69, $res['summen_nach_steuersatz']['19'], 'PDF prints 5.69, perfectly matching XML');
    }

    /**
     * Scenario 8: Test UNECE Rec 20 Unit Code mapping.
     */
    public function testUnitCodeMapping()
    {
        echo "\nTest 8: UNECE Rec 20 Unit Code Mapping\n";

        $this->assertEquals('C62', $this->service->determineUnitCode('Stk'), 'Stk -> C62');
        $this->assertEquals('C62', $this->service->determineUnitCode('Stück'), 'Stück -> C62');
        $this->assertEquals('HUR', $this->service->determineUnitCode('Std'), 'Std -> HUR');
        $this->assertEquals('HUR', $this->service->determineUnitCode('Stunden'), 'Stunden -> HUR');
        $this->assertEquals('KGM', $this->service->determineUnitCode('kg'), 'kg -> KGM');
        $this->assertEquals('MTR', $this->service->determineUnitCode('m'), 'm -> MTR');
        $this->assertEquals('XPP', $this->service->determineUnitCode('pauschal'), 'pauschal -> XPP');
        $this->assertEquals('DAY', $this->service->determineUnitCode('Tage'), 'Tage -> DAY');
        $this->assertEquals('LTR', $this->service->determineUnitCode('Liter'), 'Liter -> LTR');
    }

    /**
     * Scenario 9: Test dynamic XRechnung Smarty template rendering.
     */
    public function testXRechnungTemplateRendering()
    {
        echo "\nTest 9: Dynamic XRechnung UBL Template Rendering\n";

        require_once __DIR__ . '/../../vendor/smarty/smarty/libs/Smarty.class.php';

        $kopf = [
            'id' => 1,
            'belegnr' => '400001',
            'datum' => '2026-09-25',
            'waehrung' => 'EUR',
            'zahlungsweise' => 'rechnung',
            'zahlungszieltage' => 14,
            'payment_means_code' => '58',
            'faelligkeitsdatum' => '2026-10-09',
            'freifeld1' => '04011000-12345-67',
            'ihrebestellnummer' => 'PO-9988',
            'bearbeiter' => 'Max Mustermann',
        ];

        $rechnungssteller = [
            'name' => 'OpenXE Tech GmbH',
            'strasse' => 'Hauptstr. 1',
            'plz' => '80331',
            'ort' => 'Muenchen',
            'land' => 'DE',
            'steuernummer' => '143/123/45678',
            'ustid' => 'DE987654321',
            'iban' => 'DE89370400440532013000',
            'bic' => 'BYLADEM1001',
            'email' => 'rechnung@openxe-tech.de',
            'telefon' => '+49 89 1234567',
            'handelsregister' => 'HRB 123456',
            'amtsgericht' => 'Amtsgericht Muenchen',
        ];

        $adresse = [
            'name' => 'Kunden AG',
            'strasse' => 'Kundenweg 2',
            'plz' => '10115',
            'ort' => 'Berlin',
            'land' => 'DE',
            'kundennummer' => 'KD10020',
            'ustid' => 'DE112233445',
            'leitwegid' => '04011000-12345-67',
        ];

        $bearbeiter = [
            'name' => 'Mustermann',
            'vorname' => 'Max',
            'email' => 'm.mustermann@openxe-tech.de',
            'telefon' => '+49 89 1234567-88',
        ];

        $positions = [
            [
                'id' => 1,
                'menge' => 2,
                'preis' => 50.00,
                'rabatt' => 0,
                'steuersatz' => 19.0,
                'bezeichnung' => 'Entwicklung & Beratung',
                'beschreibung' => 'Architekturberatung E-Rechnung',
                'nummer' => 'ART-001',
                'einheit' => 'Std',
            ],
            [
                'id' => 2,
                'menge' => 1,
                'preis' => 25.00,
                'rabatt' => 0,
                'steuersatz' => 7.0,
                'bezeichnung' => 'Fachbuch E-Invoicing',
                'beschreibung' => 'Dokumentation',
                'nummer' => 'ART-002',
                'einheit' => 'Stk',
                'ean' => '4012345678901',
            ]
        ];

        $calculated = $this->service->calculateDocument('rechnung', $kopf, $positions, $adresse);

        $data = [
            'doctype' => 'rechnung',
            'id' => 1,
            'kopf' => $kopf,
            'rechnungssteller' => $rechnungssteller,
            'adresse' => $adresse,
            'bearbeiter' => $bearbeiter,
            'positionen' => $calculated['positionen'],
            'steuern' => $calculated['steuern'],
            'summen' => $calculated['summen'],
        ];

        $templatePath = __DIR__ . '/../../www/lib/rechnung/xrechnung_smarty_template.xml';
        $template = file_get_contents($templatePath);

        $smarty = new Smarty();
        $tmpDir = sys_get_temp_dir() . '/smarty_unit_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $smarty->setCompileDir($tmpDir);
        $smarty->assign('rechnung', $data);

        $xml = $smarty->fetch('string:' . $template);

        // Check XML validity using DOMDocument
        $dom = new DOMDocument();
        $valid = $dom->loadXML($xml);
        $this->assert($valid, 'Rendered XRechnung XML is well-formed XML');

        // Check that no dummy placeholders remain
        $dummies = ['info@musterfirma.de', '0123567890', 'DE12345678', 'HIER MANUELL'];
        $foundDummy = false;
        foreach ($dummies as $d) {
            if (strpos($xml, $d) !== false) {
                $foundDummy = true;
            }
        }
        $this->assert(!$foundDummy, 'No hardcoded dummy placeholders in rendered XRechnung');

        // Verify dynamic values in XML
        $this->assert(strpos($xml, 'DE89370400440532013000') !== false, 'Contains dynamic IBAN');
        $this->assert(strpos($xml, 'BYLADEM1001') !== false, 'Contains dynamic BIC');
        $this->assert(strpos($xml, 'rechnung@openxe-tech.de') !== false, 'Contains dynamic supplier email');
        $this->assert(strpos($xml, 'm.mustermann@openxe-tech.de') !== false, 'Contains dynamic clerk email');
        $this->assert(strpos($xml, '04011000-12345-67') !== false, 'Contains Leitweg-ID');
        $this->assert(strpos($xml, '4012345678901') !== false, 'Contains line item EAN');

        // Verify totals and line units
        $invoicedQuantities = $dom->getElementsByTagName('InvoicedQuantity');
        $this->assertEquals('HUR', $invoicedQuantities->item(0)->getAttribute('unitCode'), 'Position 1 has unitCode HUR');
        $this->assertEquals('C62', $invoicedQuantities->item(1)->getAttribute('unitCode'), 'Position 2 has unitCode C62');

        $lineExtensionAmounts = $dom->getElementsByTagName('LineExtensionAmount');
        $this->assertEquals('125.00', $lineExtensionAmounts->item(0)->nodeValue, 'LineExtensionAmount is 125.00');

        $payableAmounts = $dom->getElementsByTagName('PayableAmount');
        $this->assertEquals('145.75', $payableAmounts->item(0)->nodeValue, 'PayableAmount is 145.75');

        // Clean up temp dir
        $files = glob($tmpDir . '/*');
        foreach ($files as $f) {
            unlink($f);
        }
        rmdir($tmpDir);
    }
}

$test = new DocumentDataServiceTest();
$test->run();
