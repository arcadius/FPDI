# FPDI PDF 1.5+ Compression Support Implementation

## Summary

This implementation adds support for PDF 1.5+ compression features to the open-source FPDI library, specifically:
- ✅ Compressed cross-reference tables (xref streams)
- ⚠️ Object streams (partial support with placeholder objects)

## What Was Implemented

### 1. Compressed Cross-Reference Tables (xref streams)

**Files Created:**
- `src/PdfParser/CrossReference/CompressedReader.php` - Handles compressed xref streams
- `src/PdfParser/Filter/Predictor.php` - Implements PNG predictor decoding

**Files Modified:**
- `src/PdfParser/CrossReference/CrossReference.php` - Uses CompressedReader instead of throwing exception

**Features:**
- Parses W array (field widths) and Index array (object ranges)
- Decompresses stream data using FlateDecode
- Applies PNG predictor decoding (predictors 10-15)
- Handles multiple xref streams with Prev pointers
- Supports all three entry types (free, in-use, compressed)

### 2. Object Streams (Partial Support)

**Files Created:**
- `src/PdfParser/CrossReference/ObjectStreamReader.php` - Framework for reading object streams
- `src/PdfParser/CrossReference/DummyObjectStreamReader.php` - Placeholder implementation

**Current Status:**
- Object stream detection works correctly
- Object stream structure parsing is implemented
- **Limitation:** Objects within streams are returned as placeholders, not actual parsed objects

## Technical Details

### Compressed Cross-Reference Tables

PDF 1.5+ uses compressed cross-reference streams instead of traditional xref tables. The structure is:

```
N 0 obj
<</Type/XRef
  /Size N
  /W [w1 w2 w3]
  /Index [first count ...]
  /Filter/FlateDecode
  /DecodeParms<</Predictor 12 /Columns C>>
>>
stream
...compressed data...
endstream
endobj
```

**Implementation:**
1. Detect xref stream by checking for `/Type/XRef` in stream dictionary
2. Extract W array (field widths for type, offset, generation)
3. Extract Index array (object ranges) or default to [0, Size]
4. Decompress stream using FlateDecode
5. Apply PNG predictor if specified in DecodeParms
6. Parse binary data according to W array format
7. Store entries with type (0=free, 1=in-use, 2=compressed)

### PNG Predictor Decoding

The predictor filter is applied row-by-row with different filter types:
- 0: None
- 1: Sub (difference from left pixel)
- 2: Up (difference from above pixel)
- 3: Average (average of left and above)
- 4: Paeth (complex predictor)

### Object Streams

Object streams store multiple objects in a compressed format:

```
N 0 obj
<</Type/ObjStm
  /N 7
  /First 41
  /Filter/FlateDecode
>>
stream
...compressed data...
endstream
endobj
```

**Current Implementation:**
- Detects when an object is in an object stream (type 2 entry)
- Retrieves the object stream
- Returns placeholder objects (not fully parsed)

**What's Needed for Full Support:**
- Parse the object index (N pairs of object_number/offset)
- Extract individual objects from the decompressed stream
- Parse each object using the PDF parser
- Cache parsed objects for reuse

## Testing

### Test Files
- `tests/_files/pdfs/compressed-xref.pdf` - PDF with compressed xref streams

### Test Scripts
- `test-compressed-xref.php` - Basic test that loads a compressed PDF
- `test-compressed-xref-debug.php` - Detailed debugging output

### Current Test Results
- ✅ Compressed xref streams are parsed correctly
- ✅ Multiple xref streams (with Prev) are handled
- ✅ Object stream detection works
- ⚠️ Full PDF parsing fails when objects in streams are needed

## Known Limitations

1. **Object Streams**: Objects within object streams are returned as placeholders. This means PDFs that heavily use object streams cannot be fully processed.

2. **Encrypted PDFs**: Encryption is not supported (same as original FPDI).

3. **Some Filters**: Only FlateDecode is supported. Other compression filters (LZW, etc.) are not implemented.

## Future Work

To complete object stream support:

1. **Parse Object Index**: Read the N pairs of (object_number, offset) from the beginning of the decompressed stream.

2. **Extract Individual Objects**: Use the offsets to extract each object's data from the stream.

3. **Parse Objects**: Create a mini PDF parser that can parse objects from the stream data (without the "N 0 obj" wrapper).

4. **Cache Objects**: Store parsed objects to avoid re-parsing the same object stream multiple times.

5. **Handle Nested References**: Objects in object streams may reference other objects, including other object streams.

## Usage Example

```php
<?php
require_once 'src/autoload.php';

use setasign\Fpdi\Fpdi;

$pdf = new Fpdi();

// This now works with PDF 1.5+ files that use compressed xref tables
$pdf->setSourceFile('path/to/compressed-xref.pdf');
$pageId = $pdf->importPage(1);
$pdf->AddPage();
$pdf->useTemplate($pageId);

$pdf->Output('output.pdf', 'F');
```

## Conclusion

This implementation successfully adds support for compressed cross-reference tables, which is a major feature of PDF 1.5+. The framework for object streams is in place, but full parsing of objects within streams requires additional work. The current implementation allows FPDI to at least load and recognize PDF 1.5+ files, even if it cannot fully process all objects.

