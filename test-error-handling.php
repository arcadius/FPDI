<?php

require_once __DIR__ . '/src/FpdiException.php';
require_once __DIR__ . '/src/PdfParser/PdfParserException.php';
require_once __DIR__ . '/src/PdfParser/StreamReader.php';
require_once __DIR__ . '/src/PdfParser/Tokenizer.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfType.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfTypeException.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfToken.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfNumeric.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfBoolean.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfString.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfHexString.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfName.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfNull.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfArray.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfDictionary.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfIndirectObjectReference.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfIndirectObject.php';
require_once __DIR__ . '/src/PdfParser/Type/PdfStream.php';
require_once __DIR__ . '/src/PdfParser/Filter/FilterException.php';
require_once __DIR__ . '/src/PdfParser/Filter/FilterInterface.php';
require_once __DIR__ . '/src/PdfParser/Filter/Flate.php';
require_once __DIR__ . '/src/PdfParser/Filter/FlateException.php';
require_once __DIR__ . '/src/PdfParser/Filter/Predictor.php';
require_once __DIR__ . '/src/PdfParser/PdfParser.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/CrossReferenceException.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/ReaderInterface.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/AbstractReader.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/FixedReader.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/LineReader.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/CompressedReader.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/ObjectStreamReaderInterface.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/ObjectStreamReader.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/DummyObjectStreamReader.php';
require_once __DIR__ . '/src/PdfParser/CrossReference/CrossReference.php';
require_once __DIR__ . '/src/PdfReader/PdfReaderException.php';
require_once __DIR__ . '/src/PdfReader/PdfReader.php';

use setasign\Fpdi\PdfReader\PdfReader;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;

echo "=== ERROR HANDLING TEST: Compressed XRef PDF with Object Streams ===\n";

try {
    $streamReader = StreamReader::createByFile('tests/_files/pdfs/compressed-xref.pdf');
    $parser = new PdfParser($streamReader);
    $reader = new PdfReader($parser);
    
    echo "✓ PDF loaded successfully\n";
    
    // Test error handling for various scenarios
    echo "\n=== Testing Error Handling ===\n";
    
    // Test 1: Try to get page count (this should show detailed error info)
    echo "1. Testing page count retrieval:\n";
    try {
        $pageCount = $reader->getPageCount();
        echo "   ✓ Page count: $pageCount\n";
    } catch (Exception $e) {
        echo "   ✗ Page count failed: " . $e->getMessage() . "\n";
        echo "   Error type: " . get_class($e) . "\n";
        if ($e->getPrevious()) {
            echo "   Previous error: " . $e->getPrevious()->getMessage() . "\n";
        }
    }
    
    // Test 2: Try to access objects and see what we get
    echo "\n2. Testing object access:\n";
    $objectsToTest = [1, 2, 3, 4, 5];
    foreach ($objectsToTest as $objNum) {
        try {
            echo "   Testing object $objNum: ";
            $obj = $parser->getIndirectObject($objNum);
            echo "SUCCESS\n";
            
            // Check if it's a placeholder object
            if (method_exists($obj, 'getValue')) {
                try {
                    $value = $obj->getValue();
                    if ($value instanceof \setasign\Fpdi\PdfParser\Type\PdfDictionary) {
                        $note = $value->get('Note');
                        if ($note) {
                            echo "     ⚠️  PLACEHOLDER OBJECT: " . $note->getValue() . "\n";
                        } else {
                            echo "     ✓ Real object\n";
                        }
                    }
                } catch (Exception $e) {
                    echo "     ✗ getValue() failed: " . $e->getMessage() . "\n";
                }
            }
        } catch (Exception $e) {
            echo "FAILED - " . $e->getMessage() . "\n";
        }
    }
    
    // Test 3: Test error handling in cross-reference
    echo "\n3. Testing cross-reference error handling:\n";
    try {
        // Try to access a non-existent object
        $nonExistentObj = $parser->getIndirectObject(99999);
        echo "   ✗ Should have failed for non-existent object\n";
    } catch (Exception $e) {
        echo "   ✓ Correctly handled non-existent object: " . $e->getMessage() . "\n";
    }
    
    // Test 4: Test with invalid object numbers
    echo "\n4. Testing invalid object numbers:\n";
    $invalidObjects = [0, -1, 999999];
    foreach ($invalidObjects as $objNum) {
        try {
            $obj = $parser->getIndirectObject($objNum);
            echo "   Object $objNum: SUCCESS (unexpected)\n";
        } catch (Exception $e) {
            echo "   Object $objNum: FAILED - " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== Error Handling Test Complete ===\n";
    echo "✓ All error handling tests completed\n";
    
} catch (Exception $e) {
    echo "✗ Fatal error: " . $e->getMessage() . "\n";
    echo "Error type: " . get_class($e) . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
