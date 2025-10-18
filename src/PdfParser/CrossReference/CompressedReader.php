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
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;

/**
 * Class CompressedReader
 *
 * This reader handles compressed cross-reference streams (PDF 1.5+).
 */
class CompressedReader extends AbstractReader implements ReaderInterface
{
    /**
     * @var array
     */
    protected $entries = [];

    /**
     * @var PdfStream
     */
    protected $xrefStream;

    /**
     * @var ObjectStreamReader[]
     */
    protected $objectStreams = [];

    /**
     * @var int
     */
    protected $fileHeaderOffset = 0;

    /**
     * Set the file header offset.
     *
     * @param int $offset
     */
    public function setFileHeaderOffset($offset)
    {
        $this->fileHeaderOffset = $offset;
    }

    /**
     * CompressedReader constructor.
     *
     * @param PdfParser $parser
     * @param PdfStream $xrefStream
     * @throws CrossReferenceException
     */
    public function __construct(PdfParser $parser, PdfStream $xrefStream)
    {
        $this->parser = $parser;
        $this->xrefStream = $xrefStream;
        $this->trailer = $xrefStream->value; // The stream dictionary serves as the trailer
        $this->parseXrefStream();
    }

    /**
     * Get an offset by an object number.
     *
     * @param int $objectNumber
     * @return int|false
     */
    public function getOffsetFor($objectNumber)
    {
        if (isset($this->entries[$objectNumber])) {
            $entry = $this->entries[$objectNumber];
            // Type 0 = free object, Type 1 = in-use object, Type 2 = compressed object
            if ($entry['type'] === 0) { // Free object
                return false;
            }
            if ($entry['type'] === 1) { // In-use object
                return $entry['offset'];
            }
            if ($entry['type'] === 2) { // Compressed object (in object stream)
                // For now, we don't support object streams
                throw new CrossReferenceException(
                    'Object streams are not yet supported.',
                    CrossReferenceException::COMPRESSED_XREF
                );
            }
        }

        return false;
    }

    /**
     * Parse the xref stream to extract cross-reference entries.
     *
     * @throws CrossReferenceException
     */
    protected function parseXrefStream()
    {
        $streamDict = $this->xrefStream->value;
        
        // Get the W array which defines the field widths
        $wValue = PdfDictionary::get($streamDict, 'W');
        if ($wValue === null) {
            throw new CrossReferenceException(
                'Missing W array in xref stream.',
                CrossReferenceException::INVALID_DATA
            );
        }
        
        $wArray = PdfArray::ensure($wValue);
        $w = [];
        foreach ($wArray->value as $wItem) {
            $w[] = PdfNumeric::ensure($wItem)->value;
        }

        // Get the Index array which defines the object ranges
        $indexValue = PdfDictionary::get($streamDict, 'Index');
        if ($indexValue instanceof PdfNull) {
            // If no Index array, use Size to determine range
            $size = PdfDictionary::get($streamDict, 'Size');
            $sizeNum = PdfNumeric::ensure($size);
            $indexArray = PdfArray::create([PdfNumeric::create(0), PdfNumeric::create($sizeNum->value)]);
        } else {
            $indexArray = PdfArray::ensure($indexValue);
        }

        // Decompress the stream data
        $streamData = $this->decompressStreamData();
        
        if ($streamData === null || $streamData === '') {
            // Empty xref stream - this is OK, just means no entries
            return;
        }
        
        // Parse the compressed data
        $this->parseCompressedData($streamData, $w, $indexArray);
    }

    /**
     * Decompress the stream data using the appropriate filter.
     *
     * @return string
     * @throws CrossReferenceException
     */
    protected function decompressStreamData()
    {
        $streamDict = $this->xrefStream->value;
        
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
                if (!($decodeParms instanceof PdfNull)) {
                    $decodeDict = PdfDictionary::ensure($decodeParms);
                    $streamData = Predictor::decode($streamData, $decodeDict);
                }
                
                return $streamData;
            } catch (\Exception $e) {
                throw new CrossReferenceException(
                    'Failed to decompress xref stream data: ' . $e->getMessage(),
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
        $reflection = new \ReflectionClass($this->xrefStream);
        $streamProperty = $reflection->getProperty('stream');
        $streamProperty->setAccessible(true);
        return $streamProperty->getValue($this->xrefStream);
    }

    /**
     * Parse the decompressed data according to the W array format.
     *
     * @param string $data
     * @param array $w
     * @param PdfArray $indexArray
     * @throws CrossReferenceException
     */
    protected function parseCompressedData($data, array $w, PdfArray $indexArray)
    {
        $pos = 0;

        if ($indexArray->value === null || !is_array($indexArray->value)) {
            throw new CrossReferenceException(
                'Invalid Index array in xref stream.',
                CrossReferenceException::INVALID_DATA
            );
        }

        // Process each index range
        for ($i = 0; $i < count($indexArray->value); $i += 2) {
            $firstObj = PdfNumeric::ensure($indexArray->value[$i])->value;
            $count = PdfNumeric::ensure($indexArray->value[$i + 1])->value;

            for ($objNum = $firstObj; $objNum < $firstObj + $count; $objNum++) {
                $entry = $this->parseEntry($data, $pos, $w);
                if ($entry === null) {
                    break;
                }

                $this->entries[$objNum] = $entry;
                $pos += array_sum($w);
            }
        }
    }

    /**
     * Parse a single cross-reference entry from the compressed data.
     *
     * @param string $data
     * @param int $pos
     * @param array $w
     * @return array|null
     */
    protected function parseEntry($data, $pos, array $w)
    {
        // Default type is 1 if W[0] is 0
        $entry = ['type' => ($w[0] == 0 ? 1 : 0), 'offset' => 0, 'generation' => 0];
        $fieldPos = 0;

        foreach ($w as $fieldWidth) {
            if ($fieldWidth == 0) {
                // Skip fields with 0 width (use default value)
                $fieldPos++;
                continue;
            }
            
            if ($pos + $fieldWidth > strlen($data)) {
                return null;
            }

            $fieldData = substr($data, $pos, $fieldWidth);
            $value = $this->parseFieldValue($fieldData);

            switch ($fieldPos) {
                case 0: // Type
                    $entry['type'] = $value;
                    break;
                case 1: // Offset or object number
                    $entry['offset'] = $value;
                    break;
                case 2: // Generation number
                    $entry['generation'] = $value;
                    break;
                default:
                    // Ignore additional fields
                    break;
            }

            $pos += $fieldWidth;
            $fieldPos++;
        }

        return $entry;
    }

    /**
     * Parse a field value from binary data.
     *
     * @param string $data
     * @return int
     */
    protected function parseFieldValue($data)
    {
        $value = 0;
        $length = strlen($data);
        
        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | ord($data[$i]);
        }
        
        return $value;
    }

    /**
     * Get an entry for a specific object number.
     *
     * @param int $objectNumber
     * @return array|null
     */
    public function getEntryFor($objectNumber)
    {
        if (isset($this->entries[$objectNumber])) {
            return $this->entries[$objectNumber];
        }

        return null;
    }

    /**
     * Get an object from an object stream.
     *
     * @param int $objectNumber
     * @param int $streamObjectNumber
     * @return PdfIndirectObject
     * @throws CrossReferenceException
     */
    public function getObjectFromStream($objectNumber, $streamObjectNumber)
    {
        // Check if we already have this object stream reader
        if (!isset($this->objectStreams[$streamObjectNumber])) {
            // Get the object stream directly from the parser without going through cross-reference
            $streamOffset = $this->getOffsetFor($streamObjectNumber);
            if ($streamOffset === false) {
                throw new CrossReferenceException(
                    'Object stream not found.',
                    CrossReferenceException::OBJECT_NOT_FOUND
                );
            }

            $parser = $this->parser;
            $parser->getTokenizer()->clearStack();
            $parser->getStreamReader()->reset($streamOffset + $this->fileHeaderOffset);

            try {
                $streamObject = $parser->readValue(null, PdfIndirectObject::class);
                if (!($streamObject->value instanceof PdfStream)) {
                    throw new CrossReferenceException(
                        'Object stream not found.',
                        CrossReferenceException::OBJECT_NOT_FOUND
                    );
                }

                // Validate that it's actually an object stream
                $stream = $streamObject->value;
                $type = PdfDictionary::get($stream->value, 'Type');
                if ($type->value !== 'ObjStm') {
                    throw new CrossReferenceException(
                        "Object $streamObjectNumber is not an object stream (Type: {$type->value}).",
                        CrossReferenceException::INVALID_DATA
                    );
                }

                // Create object stream reader
                $this->objectStreams[$streamObjectNumber] = new ObjectStreamReader($this->parser, $stream);
                
            } catch (CrossReferenceException $e) {
                // Re-throw our custom exceptions
                throw $e;
            } catch (\Exception $e) {
                // Log the error and create a dummy reader
                error_log("Failed to create object stream reader for object $streamObjectNumber: " . $e->getMessage());
                $this->objectStreams[$streamObjectNumber] = new DummyObjectStreamReader();
            }
        }

        $objectStreamReader = $this->objectStreams[$streamObjectNumber];
        $object = $objectStreamReader->getObject($objectNumber);
        
        if ($object === null) {
            throw new CrossReferenceException(
                \sprintf('Object (id:%s) not found in object stream.', $objectNumber),
                CrossReferenceException::OBJECT_NOT_FOUND
            );
        }

        return $object;
    }
}
