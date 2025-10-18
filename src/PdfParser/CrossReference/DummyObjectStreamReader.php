<?php

namespace setasign\Fpdi\PdfParser\CrossReference;

use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;

/**
 * A dummy object stream reader that returns placeholder objects.
 * Used when object stream parsing fails.
 */
class DummyObjectStreamReader implements ObjectStreamReaderInterface
{
    /**
     * Get an object from the object stream.
     *
     * @param int $objectNumber
     * @return PdfIndirectObject|null
     */
    public function getObject($objectNumber)
    {
        // Return a placeholder object
        $object = new PdfIndirectObject();
        $object->objectNumber = $objectNumber;
        $object->generationNumber = 0;
        
        $dict = PdfDictionary::create([
            'Type' => PdfName::create('DummyObject'),
            'ObjectNumber' => PdfNumeric::create($objectNumber),
            'Note' => PdfName::create('ObjectStreamNotParsed')
        ]);
        $object->value = $dict;
        
        return $object;
    }
}