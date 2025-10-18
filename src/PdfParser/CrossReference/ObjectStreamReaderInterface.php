<?php

namespace setasign\Fpdi\PdfParser\CrossReference;

use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;

/**
 * Interface for object stream readers.
 */
interface ObjectStreamReaderInterface
{
    /**
     * Get an object from the object stream.
     *
     * @param int $objectNumber
     * @return PdfIndirectObject|null
     */
    public function getObject($objectNumber);
}
