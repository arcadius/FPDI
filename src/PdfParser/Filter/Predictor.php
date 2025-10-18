<?php

/**
 * This file is part of FPDI
 *
 * @package   setasign\Fpdi
 * @copyright Copyright (c) 2024 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace setasign\Fpdi\PdfParser\Filter;

use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;

/**
 * Class for handling predictor functions
 */
class Predictor
{
    /**
     * Apply predictor decoding to data.
     *
     * @param string $data
     * @param PdfDictionary|null $params
     * @return string
     */
    public static function decode($data, $params = null)
    {
        try {
            // Validate input data
            if (empty($data)) {
                throw new FilterException(
                    'Cannot decode empty data with predictor.',
                    FilterException::DECODE_ERROR
                );
            }
            
            if ($params === null) {
                return $data;
            }

            $predictor = 1;
            $colors = 1;
            $bitsPerComponent = 8;
            $columns = 1;

            $predictorValue = PdfDictionary::get($params, 'Predictor');
            if ($predictorValue && !($predictorValue instanceof \setasign\Fpdi\PdfParser\Type\PdfNull)) {
                try {
                    $predictor = PdfNumeric::ensure($predictorValue)->value;
                    
                    // Validate predictor value
                    if ($predictor < 1 || $predictor > 15) {
                        throw new FilterException(
                            "Invalid predictor value: $predictor (must be 1-15).",
                            FilterException::DECODE_ERROR
                        );
                    }
                } catch (FilterException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    // If we can't get the predictor, assume no prediction
                    return $data;
                }
            }

            if ($predictor === 1) {
                // No prediction
                return $data;
            }

        $colorsValue = PdfDictionary::get($params, 'Colors');
        if ($colorsValue && !($colorsValue instanceof \setasign\Fpdi\PdfParser\Type\PdfNull)) {
            try {
                $colors = PdfNumeric::ensure($colorsValue)->value;
            } catch (\Exception $e) {
                // Use default
            }
        }

        $bpcValue = PdfDictionary::get($params, 'BitsPerComponent');
        if ($bpcValue && !($bpcValue instanceof \setasign\Fpdi\PdfParser\Type\PdfNull)) {
            try {
                $bitsPerComponent = PdfNumeric::ensure($bpcValue)->value;
            } catch (\Exception $e) {
                // Use default
            }
        }

        $columnsValue = PdfDictionary::get($params, 'Columns');
        if ($columnsValue && !($columnsValue instanceof \setasign\Fpdi\PdfParser\Type\PdfNull)) {
            try {
                $columns = PdfNumeric::ensure($columnsValue)->value;
            } catch (\Exception $e) {
                // Use default
            }
        }

            if ($predictor >= 10 && $predictor <= 15) {
                // PNG prediction
                return self::decodePngPrediction($data, $colors, $bitsPerComponent, $columns);
            }

            // TIFF prediction (predictor == 2) not implemented
            return $data;
            
        } catch (FilterException $e) {
            // Re-throw our custom exceptions
            throw $e;
        } catch (\Exception $e) {
            // Wrap unexpected exceptions
            throw new FilterException(
                'Unexpected error in predictor decode: ' . $e->getMessage(),
                FilterException::DECODE_ERROR,
                $e
            );
        }
    }

    /**
     * Decode PNG prediction.
     *
     * @param string $data
     * @param int $colors
     * @param int $bitsPerComponent
     * @param int $columns
     * @return string
     */
    protected static function decodePngPrediction($data, $colors, $bitsPerComponent, $columns)
    {
        $rowLength = (int) ceil($columns * $colors * $bitsPerComponent / 8);
        $dataLength = strlen($data);
        $output = '';
        $pos = 0;
        $prevRow = str_repeat("\x00", $rowLength);

        while ($pos < $dataLength) {
            if ($pos + $rowLength + 1 > $dataLength) {
                break;
            }

            $predictor = ord($data[$pos]);
            $pos++;

            $row = substr($data, $pos, $rowLength);
            $pos += $rowLength;

            $decodedRow = self::decodePngRow($row, $prevRow, $predictor, $rowLength);
            $output .= $decodedRow;
            $prevRow = $decodedRow;
        }

        return $output;
    }

    /**
     * Decode a single PNG row.
     *
     * @param string $row
     * @param string $prevRow
     * @param int $predictor
     * @param int $rowLength
     * @return string
     */
    protected static function decodePngRow($row, $prevRow, $predictor, $rowLength)
    {
        $output = '';

        for ($i = 0; $i < $rowLength; $i++) {
            $raw = ord($row[$i]);
            $prior = ord($prevRow[$i]);
            $left = $i > 0 ? ord($output[$i - 1]) : 0;
            $upperLeft = $i > 0 ? ord($prevRow[$i - 1]) : 0;

            switch ($predictor) {
                case 0: // None
                    $byte = $raw;
                    break;
                case 1: // Sub
                    $byte = ($raw + $left) % 256;
                    break;
                case 2: // Up
                    $byte = ($raw + $prior) % 256;
                    break;
                case 3: // Average
                    $byte = ($raw + (int) floor(($left + $prior) / 2)) % 256;
                    break;
                case 4: // Paeth
                    $byte = ($raw + self::paethPredictor($left, $prior, $upperLeft)) % 256;
                    break;
                default:
                    $byte = $raw;
            }

            $output .= chr($byte);
        }

        return $output;
    }

    /**
     * Paeth predictor function.
     *
     * @param int $a
     * @param int $b
     * @param int $c
     * @return int
     */
    protected static function paethPredictor($a, $b, $c)
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        } elseif ($pb <= $pc) {
            return $b;
        } else {
            return $c;
        }
    }
}

