<?php

/**
 * This file is part of FPDI
 *
 * @package   setasign\Fpdi
 * @copyright Copyright (c) 2024 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace setasign\Fpdi\PdfParser\CrossReference;

use setasign\Fpdi\PdfParser\Filter\Flate;
use setasign\Fpdi\PdfParser\Filter\Predictor;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;

/**
 * Class ObjectStreamReader
 *
 * This reader handles object streams (PDF 1.5+).
 */
class ObjectStreamReader implements ObjectStreamReaderInterface
{
    /**
     * @var PdfParser
     */
    protected $parser;

    /**
     * @var PdfStream
     */
    protected $objectStream;

    /**
     * @var array
     */
    protected $objects = [];

    /**
     * @var array
     */
    protected $objectOffsets = [];

    /**
     * ObjectStreamReader constructor.
     *
     * @param PdfParser $parser
     * @param PdfStream $objectStream
     * @throws CrossReferenceException
     */
    public function __construct(PdfParser $parser, PdfStream $objectStream)
    {
        $this->parser = $parser;
        $this->objectStream = $objectStream;
        $this->parseObjectStream();
    }

    /**
     * Get an object from the object stream.
     *
     * @param int $objectNumber
     * @return PdfIndirectObject|null
     */
    public function getObject($objectNumber)
    {
        if (isset($this->objects[$objectNumber])) {
            return $this->objects[$objectNumber];
        }

        return null;
    }

    /**
     * Check if an object exists in this stream.
     *
     * @param int $objectNumber
     * @return bool
     */
    public function hasObject($objectNumber)
    {
        return isset($this->objects[$objectNumber]);
    }

    /**
     * Parse the object stream to extract all objects.
     *
     * @throws CrossReferenceException
     */
    protected function parseObjectStream()
    {
        $streamDict = $this->objectStream->value;
        
        // Get the number of objects
        $nValue = PdfDictionary::get($streamDict, 'N');
        if ($nValue === null) {
            throw new CrossReferenceException(
                'Missing N field in object stream.',
                CrossReferenceException::INVALID_DATA
            );
        }
        $n = PdfNumeric::ensure($nValue)->value;

        // Get the offset to the first object
        $firstValue = PdfDictionary::get($streamDict, 'First');
        if ($firstValue === null) {
            throw new CrossReferenceException(
                'Missing First field in object stream.',
                CrossReferenceException::INVALID_DATA
            );
        }
        $first = PdfNumeric::ensure($firstValue)->value;

        // Decompress the stream data
        $streamData = $this->decompressStreamData();
        
        if ($streamData === null || $streamData === '') {
            throw new CrossReferenceException(
                'Failed to extract object stream data.',
                CrossReferenceException::INVALID_DATA
            );
        }

        // Parse the object index
        $this->parseObjectIndex($streamData, $n, $first);
        
        // Parse the objects
        $this->parseObjects($streamData, $n, $first);
    }

    /**
     * Decompress the stream data using the appropriate filter.
     *
     * @return string
     * @throws CrossReferenceException
     */
    protected function decompressStreamData()
    {
        $streamDict = $this->objectStream->value;
        
        // Get the length of the stream
        $length = PdfDictionary::get($streamDict, 'Length');
        $lengthValue = PdfNumeric::ensure($length)->value;
        
        // Get the raw stream data by reading directly from the stream reader
        $streamReader = $this->parser->getStreamReader();
        $currentPos = $streamReader->getPosition() + $streamReader->getOffset();
        
        // The stream offset is stored in the PdfStream object
        $streamOffset = $this->getStreamOffset();
        $streamReader->reset($streamOffset);
        $rawStreamData = $streamReader->readBytes($lengthValue);
        
        // Restore the original position
        $streamReader->reset($currentPos);

        // Check for FlateDecode filter
        $filter = PdfDictionary::get($streamDict, 'Filter');
        if ($filter && $filter->value === 'FlateDecode') {
            try {
                $flate = new Flate();
                $streamData = $flate->decode($rawStreamData);
                
                // Check for decode parameters (predictor)
                $decodeParms = PdfDictionary::get($streamDict, 'DecodeParms');
                if (!($decodeParms instanceof \setasign\Fpdi\PdfParser\Type\PdfNull)) {
                    $decodeDict = PdfDictionary::ensure($decodeParms);
                    $streamData = Predictor::decode($streamData, $decodeDict);
                }
                
                return $streamData;
            } catch (\Exception $e) {
                throw new CrossReferenceException(
                    'Failed to decompress object stream data: ' . $e->getMessage(),
                    CrossReferenceException::INVALID_DATA,
                    $e
                );
            }
        }

        // If no filter or unsupported filter, return raw data
        return $rawStreamData;
    }
    
    /**
     * Get the stream offset from the PdfStream object.
     *
     * @return int
     */
    protected function getStreamOffset()
    {
        // Access the protected stream property via reflection
        $reflection = new \ReflectionClass($this->objectStream);
        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setAccessible(true);
        return $streamProperty->getValue($this->objectStream);
    }

    /**
     * Parse the object index to get object numbers and offsets.
     *
     * @param string $data
     * @param int $n
     * @param int $first
     * @throws CrossReferenceException
     */
    protected function parseObjectIndex($data, $n, $first)
    {
        try {
            // Validate input parameters
            if ($n <= 0) {
                throw new CrossReferenceException(
                    'Invalid object count in object stream (N must be > 0).',
                    CrossReferenceException::INVALID_DATA
                );
            }
            
            if ($first < 0 || $first > strlen($data)) {
                throw new CrossReferenceException(
                    "Invalid first offset in object stream (first: $first, data length: " . strlen($data) . ").",
                    CrossReferenceException::INVALID_DATA
                );
            }
            
            // The object index is at the beginning of the stream data
            // It contains n pairs of (object_number, offset)
            $indexData = substr($data, 0, $first);
            
            if (empty(trim($indexData))) {
                throw new CrossReferenceException(
                    'Empty object index in object stream.',
                    CrossReferenceException::INVALID_DATA
                );
            }
            
            // Parse the index as space-separated integers
            $indexValues = preg_split('/\s+/', trim($indexData));
            
            if (count($indexValues) < $n * 2) {
                throw new CrossReferenceException(
                    "Invalid object index in object stream. Expected " . ($n * 2) . " values, got " . count($indexValues) . ".",
                    CrossReferenceException::INVALID_DATA
                );
            }
            
            for ($i = 0; $i < $n; $i++) {
                $objNum = (int) $indexValues[$i * 2];
                $offset = (int) $indexValues[$i * 2 + 1];
                
                // Validate object number and offset
                if ($objNum <= 0) {
                    throw new CrossReferenceException(
                        "Invalid object number in index: $objNum",
                        CrossReferenceException::INVALID_DATA
                    );
                }
                
                if ($offset < 0) {
                    throw new CrossReferenceException(
                        "Invalid offset in index: $offset",
                        CrossReferenceException::INVALID_DATA
                    );
                }
                
                $this->objectOffsets[$objNum] = $first + $offset;
            }
            
            // Debug output
            error_log("ObjectStreamReader: Successfully parsed " . count($this->objectOffsets) . " objects");
            foreach ($this->objectOffsets as $objNum => $offset) {
                error_log("Object $objNum at offset $offset");
            }
            
        } catch (CrossReferenceException $e) {
            // Re-throw our custom exceptions
            throw $e;
        } catch (\Exception $e) {
            // Wrap unexpected exceptions
            throw new CrossReferenceException(
                'Unexpected error parsing object index: ' . $e->getMessage(),
                CrossReferenceException::INVALID_DATA,
                $e
            );
        }
    }

    /**
     * Parse the actual objects from the stream.
     *
     * @param string $data
     * @param int $n
     * @param int $first
     * @throws CrossReferenceException
     */
    protected function parseObjects($data, $n, $first)
    {
        foreach ($this->objectOffsets as $objNum => $offset) {
            try {
                // Create a temporary stream reader for this object
                $objectData = substr($data, $offset);
                
                // Parse the object
                $object = $this->parseObjectFromData($objectData, $objNum);
                if ($object !== null) {
                    $this->objects[$objNum] = $object;
                }
            } catch (\Exception $e) {
                // Skip objects that can't be parsed
                continue;
            }
        }
    }

    /**
     * Parse a single object from data.
     *
     * @param string $data
     * @param int $objectNumber
     * @return PdfIndirectObject|null
     */
    protected function parseObjectFromData($data, $objectNumber)
    {
        try {
            // Validate object number exists in our index
            if (!isset($this->objectOffsets[$objectNumber])) {
                throw new CrossReferenceException(
                    "Object $objectNumber not found in object stream index.",
                    CrossReferenceException::OBJECT_NOT_FOUND
                );
            }
            
            $offset = $this->objectOffsets[$objectNumber];
            error_log("Parsing object $objectNumber at offset $offset");
            
            // Validate offset is within data bounds
            if ($offset < 0 || $offset >= strlen($data)) {
                throw new CrossReferenceException(
                    "Object $objectNumber offset $offset is out of bounds (data length: " . strlen($data) . ").",
                    CrossReferenceException::INVALID_DATA
                );
            }
            
            // Create a stream reader for this object's data
            $objectData = substr($data, $offset);
            
            // Find the end of this object by looking for the next object or end of data
            $nextOffset = $this->findNextObjectOffset($objectNumber);
            if ($nextOffset !== null) {
                if ($nextOffset <= $offset) {
                    throw new CrossReferenceException(
                        "Invalid next object offset for object $objectNumber: $nextOffset <= $offset",
                        CrossReferenceException::INVALID_DATA
                    );
                }
                $objectData = substr($data, $offset, $nextOffset - $offset);
            }
            
            // Validate we have data to parse
            if (empty(trim($objectData))) {
                throw new CrossReferenceException(
                    "Object $objectNumber has no data to parse.",
                    CrossReferenceException::INVALID_DATA
                );
            }
            
            error_log("Object $objectNumber data length: " . strlen($objectData));
            error_log("Object $objectNumber data preview: " . substr($objectData, 0, 100));
            
            // Create a temporary stream reader
            $tempStream = fopen('php://temp', 'r+b');
            if ($tempStream === false) {
                throw new CrossReferenceException(
                    "Failed to create temporary stream for object $objectNumber.",
                    CrossReferenceException::INVALID_DATA
                );
            }
            
            $bytesWritten = fwrite($tempStream, $objectData);
            if ($bytesWritten === false || $bytesWritten !== strlen($objectData)) {
                fclose($tempStream);
                throw new CrossReferenceException(
                    "Failed to write object data to temporary stream for object $objectNumber.",
                    CrossReferenceException::INVALID_DATA
                );
            }
            
            rewind($tempStream);
            
            $streamReader = new \setasign\Fpdi\PdfParser\StreamReader($tempStream, true);
            $tokenizer = new \setasign\Fpdi\PdfParser\Tokenizer($streamReader);
            $parser = new \setasign\Fpdi\PdfParser\PdfParser($streamReader);
            
            // Parse the object value
            $value = $parser->readValue();
            
            if ($value === null) {
                fclose($tempStream);
                throw new CrossReferenceException(
                    "Failed to parse object $objectNumber - readValue returned null.",
                    CrossReferenceException::INVALID_DATA
                );
            }
            
            error_log("Object $objectNumber parsed successfully, type: " . get_class($value));
            
            // Create the indirect object
            $object = new PdfIndirectObject();
            $object->objectNumber = $objectNumber;
            $object->generationNumber = 0;
            $object->value = $value;
            
            fclose($tempStream);
            return $object;
            
        } catch (CrossReferenceException $e) {
            // Re-throw our custom exceptions
            error_log("CrossReferenceException parsing object $objectNumber: " . $e->getMessage());
            return $this->createPlaceholderObject($objectNumber, $e->getMessage());
        } catch (\Exception $e) {
            // Log unexpected exceptions and return placeholder
            error_log("Unexpected error parsing object $objectNumber: " . $e->getMessage());
            return $this->createPlaceholderObject($objectNumber, $e->getMessage());
        }
    }
    
    /**
     * Find the offset of the next object in the stream.
     *
     * @param int $currentObjectNumber
     * @return int|null
     */
    protected function findNextObjectOffset($currentObjectNumber)
    {
        $nextOffset = null;
        foreach ($this->objectOffsets as $objNum => $offset) {
            if ($objNum > $currentObjectNumber) {
                if ($nextOffset === null || $offset < $nextOffset) {
                    $nextOffset = $offset;
                }
            }
        }
        return $nextOffset;
    }
    
    /**
     * Create a placeholder object when parsing fails.
     *
     * @param int $objectNumber
     * @param string $errorMessage
     * @return PdfIndirectObject
     */
    protected function createPlaceholderObject($objectNumber, $errorMessage = 'ObjectStreamParsingFailed')
    {
        $object = new PdfIndirectObject();
        $object->objectNumber = $objectNumber;
        $object->generationNumber = 0;
        
        $dict = PdfDictionary::create([
            'Type' => PdfName::create('Placeholder'),
            'ObjectNumber' => PdfNumeric::create($objectNumber),
            'Note' => PdfName::create($errorMessage),
            'Error' => PdfName::create('ObjectStreamParsingFailed')
        ]);
        $object->value = $dict;
        
        return $object;
    }

}
