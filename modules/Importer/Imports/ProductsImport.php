<?php

namespace Modules\Importer\Imports;

use Exception;
use finfo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;
use Modules\Attribute\Entities\Attribute as EntitiesAttribute;
use Modules\Attribute\Entities\AttributeSet;
use Modules\Attribute\Entities\AttributeValue;
use Modules\Attribute\Entities\ProductAttribute;
use Modules\Attribute\Entities\ProductAttributeValue;
use Modules\Brand\Entities\Brand;
use Modules\Category\Entities\Category;
use Modules\Importer\Rules\ValidateOptionFormat;
use Modules\Importer\Rules\ValidAttributeFormat;
use Modules\Importer\Services\ErrorService;
use Modules\Media\Entities\File;
use Modules\Product\Entities\Product;
use Modules\Tag\Entities\Tag;
use Modules\Variation\Entities\Variation;
use Modules\Variation\Entities\VariationValue;
use Modules\Product\Entities\ProductVariant;

class ProductsImport implements OnEachRow, WithChunkReading, WithHeadingRow
{
    private ErrorService $errors;
    


    public function __construct()
    {
        $this->errors = app(ErrorService::class);
        
        // Custom heading formatter to handle duplicate SKU columns
        // 'sku' (lowercase in CSV) -> 'parent_sku' (for variant linking)
        // 'SKU' (capital in CSV) -> 'sku' (individual SKU values)
        HeadingRowFormatter::extend('custom_sku', function ($value, $key) {
            $originalValue = trim($value);
            
            // Check ORIGINAL value before any transformation
            // If exactly 'sku' (all lowercase), rename to 'parent_sku'
            if ($originalValue === 'sku') {
                return 'parent_sku';
            }
            
            // If exactly 'SKU' (all uppercase), keep as 'sku'
            if ($originalValue === 'SKU') {
                return 'sku';
            }
            
            // Default: convert to snake_case lowercase (matching default formatter behavior)
            $value = preg_replace('/\s+/', '_', $originalValue);  // spaces to underscores
            $value = preg_replace('/[^a-zA-Z0-9_]/', '', $value); // remove special chars
            $value = preg_replace('/_+/', '_', $value);           // collapse multiple underscores
            $value = trim($value, '_');                            // remove leading/trailing underscores
            return strtolower($value);
        });
        
        HeadingRowFormatter::default('custom_sku');
    }

    public function chunkSize(): int
    {
        return 200;
    }

    /**
     * Normalize column names from client format to our standard format
     * Handles columns with asterisks, spaces, and different naming conventions
     */
    private function normalizeColumnNames(array $data): array
    {
        $normalized = [];
        
        // Get parent SKU from 'parent_sku' column (was lowercase 'sku' in CSV, now renamed by formatter)
        $parentSku = null;
        foreach ($data as $key => $value) {
            if (trim($key) === 'parent_sku' && !empty($value)) {
                $parentSku = $value;
                break;
            }
        }
        
        $columnMapping = [
            // Client column => Our column
            'name *' => 'name',
            'name*' => 'name',
            'Name *' => 'name',
            'description *' => 'description',
            'description*' => 'description',
            'Description *' => 'description',
            'price *' => 'price',
            'price*' => 'price',
            'Price *' => 'price',
            'SKU' => 'sku',
            'sku' => 'sku',
            'parent_sku' => 'parent_sku',  // lowercase 'sku' from CSV becomes 'parent_sku'
            'product_code' => 'product_code',
            'Product_Code' => 'product_code',
            'Product Code' => 'product_code',
            'Barcode' => 'barcode',
            'Supplier Code' => 'supplier_code',
            'Supplier Name' => 'supplier_name',
            'Type' => 'type',
            'Active' => 'active',
            'Brand' => 'brand',
            'Categories' => 'categories',
            'Tax Class' => 'tax_class_id',
            'Tags' => 'tags',
            'Special Price' => 'special_price',
            'Special Price Type' => 'special_price_type',
            'Special Price Start' => 'special_price_start',
            'Special Price End' => 'special_price_end',
            'Manage Stock' => 'manage_stock',
            'Quantity' => 'quantity',
            'In Stock' => 'in_stock',
            'New From' => 'new_from',
            'New To' => 'new_to',
            'meta _description' => 'meta_description',
            'Meta Keywords' => 'meta_keywords',
            'Attribute:Color' => 'attribute_color',
            'attribute:color' => 'attribute_color',
            'Attribute:Size' => 'attribute_size',
            'attribute:size' => 'attribute_size',
            'Options' => 'options',
            'key_features' => 'key_features',
            'technical_specs' => 'technical_specs',
            'why_choose' => 'why_choose',
            'tips_guide' => 'tips_guide',
            // Image columns
            'Base Image' => 'base_image',
            'base_image' => 'base_image',
            'Base_Image' => 'base_image',
            'Image' => 'base_image',
            'Additional Images' => 'additional_images',
            'additional_images' => 'additional_images',
            'Additional_Images' => 'additional_images',
            'Extra Images' => 'additional_images',
        ];
        
        // DEBUG: Log all column keys to see what's coming from CSV
        Log::info("CSV Column Keys", ['keys' => array_keys($data)]);
        
        // First pass: Get the actual SKU value (check both 'SKU' and 'sku' since parser may convert)
        $actualSku = null;
        $productCode = null;
        foreach ($data as $key => $value) {
            $trimmedKey = trim($key);
            
            // Store product_code separately
            if (strtolower($trimmedKey) === 'product_code' && !empty($value)) {
                $productCode = $value;
            }
            
            // Look for SKU column (case-insensitive, but NOT product_code)
            if (strtolower($trimmedKey) === 'sku' && !empty($value)) {
                $actualSku = $value;
                Log::info("Found SKU column", ['key' => $trimmedKey, 'value' => $value, 'product_code' => $productCode]);
            }
        }
        
        // If SKU found and it's different from product_code, use it
        if (!empty($actualSku) && $actualSku !== $productCode) {
            Log::info("Using SKU (different from product_code)", ['sku' => $actualSku, 'product_code' => $productCode]);
        }
        
        foreach ($data as $key => $value) {
            // Trim the key to remove any trailing/leading spaces
            $trimmedKey = trim($key);
            
            // Check if we have a mapping for this column
            if (isset($columnMapping[$trimmedKey])) {
                $normalized[$columnMapping[$trimmedKey]] = $value;
            } else {
                // Try to match attribute columns with flexible pattern
                // CSV parser removes colons and converts to lowercase
                $lowerKey = strtolower($trimmedKey);
                
                if ($lowerKey === 'attributecolor' || 
                    stripos($trimmedKey, 'attribute:color') !== false || 
                    stripos($trimmedKey, 'attribute: color') !== false ||
                    stripos($trimmedKey, 'attribute_color') !== false) {
                    $normalized['attribute_color'] = $value;
                } elseif ($lowerKey === 'attributesize' || 
                          stripos($trimmedKey, 'attribute:size') !== false || 
                          stripos($trimmedKey, 'attribute: size') !== false ||
                          stripos($trimmedKey, 'attribute_size') !== false) {
                    $normalized['attribute_size'] = $value;
                } else {
                    // Keep original key (lowercase, with special chars replaced)
                    $normalizedKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $trimmedKey));
                    $normalized[$normalizedKey] = $value;
                }
            }
        }
        
        // Handle SKU priority:
        // 1. Use actual SKU column value if found (and different from product_code)
        // 2. Fallback to product_code only if sku column doesn't exist
        if (!empty($actualSku)) {
            // Always use SKU column value
            $normalized['sku'] = $actualSku;
            Log::info("FINAL SKU from SKU column", ['sku' => $actualSku]);
        } elseif (empty($normalized['sku']) && !empty($normalized['product_code'])) {
            // Only fallback if no SKU column exists
            $normalized['sku'] = $normalized['product_code'];
            Log::info("FINAL SKU fallback to product_code", ['sku' => $normalized['product_code']]);
        }
        
        Log::info("Final normalized data", [
            'sku' => $normalized['sku'] ?? 'EMPTY',
            'product_code' => $normalized['product_code'] ?? 'EMPTY',
        ]);
        
        // Set parent_sku for variant linking (Option A)
        // The lowercase 'sku' column contains the parent SKU for variant rows
        if (!empty($parentSku)) {
            $normalized['parent_sku'] = $parentSku;
        }
        
        Log::info("normalizeColumnNames result", [
            'sku' => $normalized['sku'] ?? 'EMPTY',
            'parent_sku' => $normalized['parent_sku'] ?? 'EMPTY',
            'type' => $normalized['type'] ?? 'N/A',
            'attribute_color' => $normalized['attribute_color'] ?? 'N/A',
            'attribute_size' => $normalized['attribute_size'] ?? 'N/A',
        ]);
        
        return $normalized;
    }

    public function onRow(Row $row): void
    {
        $row_data = $row->toArray();
        
        // DEBUG: Log raw SKU value before normalization
        $rawSku = null;
        foreach ($row_data as $key => $value) {
            if (strtolower(trim($key)) === 'sku') {
                $rawSku = $value;
                break;
            }
        }
        Log::info("RAW ROW DATA - SKU check", [
            'raw_sku' => $rawSku,
            'product_code' => $row_data['product_code'] ?? $row_data['Product_Code'] ?? 'N/A',
        ]);
        
        // Normalize column names from client format to our format
        $row_data = $this->normalizeColumnNames($row_data);
        
        $validator = Validator::make($row_data, [
            // Required fields
            "name" => "required|string|max:255",
            "categories" => "required|string",
            "price" => "required_without:variants|nullable|numeric|min:0|max:99999999999999",
            
            // Product identifiers
            "sku" => "nullable|string|max:255",
            "product_code" => "nullable|string|max:255",
            "barcode" => "nullable|string|max:255",
            
            // Supplier info
            "supplier_name" => "nullable|string|max:255",
            "supplier_code" => "nullable|string|max:255",
            
            // Product type
            "type" => "nullable|string|max:255",
            "parent_sku" => "nullable|string|max:255",
            
            // Descriptions
            "description" => "nullable|string",
            "short_description" => "nullable|string",
            "key_features" => "nullable|string",
            "technical_specs" => "nullable|string",
            "why_choose" => "nullable|string",
            "tips_guide" => "nullable|string",
            
            // Status & Brand
            "active" => "nullable|integer|in:1,0",
            "brand" => "nullable|string",
            "tags" => "nullable|string",
            "tax_class_id" => "nullable",
            
            // Pricing
            "special_price" => "nullable|numeric|min:0|max:99999999999999",
            "special_price_type" => ["nullable", Rule::in(["fixed", "percent"])],
            "special_price_start" => "nullable",
            "special_price_end" => "nullable",
            
            // Stock
            "manage_stock" => "nullable|boolean",
            "quantity" => "required_if:manage_stock,1|nullable|numeric",
            "in_stock" => "nullable|boolean",
            
            // Dates
            "new_from" => "nullable",
            "new_to" => "nullable",
            
            // Images
            "base_image" => "nullable|string",
            "additional_images" => "nullable|string",
            
            // Meta
            "meta_title" => "nullable|string",
            "meta_description" => "nullable|string",
            "meta_keywords" => "nullable|string",
            
            // Attributes (specific columns)
            "attribute_color" => "nullable|string",
            "attribute_size" => "nullable|string",
            
            // Complex fields
            "attributes" => ["nullable", new ValidAttributeFormat()],
            "options" => ["nullable", new ValidateOptionFormat()],
        ]);
 
 
 
        if ($validator->fails()) {


            $productSku = null;

            if (array_key_exists("sku", $row_data)) {
                $productSku = $row["sku"];
            }

            $messages = [];

            foreach ($validator->errors()->messages() as $field => $errors) {
                foreach ($errors as $error) {
                    $field = ucfirst($field);
                    $messages[] = "{$field}: {$error}<br />";
                }
            }

            $errorMessage = implode(". ", $messages) . ".";
            $this->errors->push(
                'product',
                sprintf(
                    "Product SKU: %s Errors: %s at Row Index: %s",
                    $productSku,
                    $errorMessage,
                    $row->getIndex()
                )
            );


            return;
        }
        try {
            $data = $this->normalize($row_data);

            $options = null;
            $attributes = null;
            $tags = [];
            $categories = [];

            if (array_key_exists("options", $data)) {
                $options = $data["options"];

                unset($data["options"]);
            }

            if (array_key_exists("attributes", $data)) {
                $attributes = $data["attributes"];

                unset($data["attributes"]);
            }

            // Extract tags and categories (many-to-many relationships)
            if (array_key_exists("tags", $data)) {
                $tags = $data["tags"];
                unset($data["tags"]);
            }

            if (array_key_exists("categories", $data)) {
                $categories = $data["categories"];
                unset($data["categories"]);
            }
            
            // Extract fields that are NOT in database but needed for variations
            $attributeColor = $data['attribute_color'] ?? null;
            $attributeSize = $data['attribute_size'] ?? null;
            $parentSku = $data['parent_sku'] ?? null;
            
            // Remove non-database fields to prevent SQL errors
            unset($data['attribute_color']);
            unset($data['attribute_size']);
            unset($data['parent_sku']);

            $data["options"] = [];
            $data['brand_id'] = $data['brand'] ?? null;

            // Option A: Check if this is a variant product (has parent_sku)
            // Variants get linked to parent product instead of creating separate products
            // Note: $parentSku was already extracted above before unset
            $parentSku = trim($parentSku ?? '');
            $productType = strtolower(trim($data['type'] ?? ''));
            $currentSku = trim($data['sku'] ?? '');
            
            // Check if this is a variant-type product (variant/varient)
            $isVariantType = ($productType === 'variant' || $productType === 'varient');
            
            // Determine if this is the PARENT row (first variant that defines the parent)
            // Parent row: has parent_sku AND current SKU equals parent_sku
            $isParentRow = !empty($parentSku) 
                && $isVariantType 
                && !empty($currentSku) 
                && strtolower($currentSku) === strtolower($parentSku);
            
            // Variant row: has parent_sku AND current SKU is DIFFERENT from parent_sku
            $isVariant = !empty($parentSku) 
                && $isVariantType
                && !empty($currentSku) 
                && strtolower($currentSku) !== strtolower($parentSku);
            
            Log::info("=== Row Processing ===", [
                'product_sku' => $data['sku'] ?? 'N/A',
                'parent_sku' => $parentSku,
                'is_parent_row' => $isParentRow,
                'is_variant' => $isVariant,
                'type' => $data['type'] ?? 'N/A',
                'attribute_color' => $attributeColor,
                'attribute_size' => $attributeSize,
            ]);
            
            // Handle PARENT ROW (first variant row where SKU == parent_sku)
            // This creates the parent product with type "variable" and also adds the first variant
            if ($isParentRow) {
                Log::info("Processing PARENT ROW - creating variable product", ['sku' => $currentSku]);
                
                // Check if parent already exists
                $existingParent = Product::where('sku', $currentSku)->first();
                if (!$existingParent) {
                    $existingParent = Product::whereRaw('LOWER(sku) = ?', [strtolower($currentSku)])->first();
                }
                
                if (!$existingParent) {
                    // Create the parent product with type "variable"
                    $data['type'] = 'variable';
                    $data['is_active'] = 1;
                    $data['is_virtual'] = 1;
                    
                    request()->merge($data);
                    $parentProduct = Product::create($data);
                    
                    if ($parentProduct) {
                        $parentProduct->barcode = $data['barcode'] ?? '';
                        $parentProduct->supplier_name = $data['supplier_name'] ?? '';
                        $parentProduct->supplier_code = $data['supplier_code'] ?? '';
                        $parentProduct->save();
                        
                        // Sync categories and tags
                        if (!empty($categories)) {
                            $parentProduct->categories()->sync($categories);
                        }
                        if (!empty($tags)) {
                            $parentProduct->tags()->sync($tags);
                        }
                        
                        Log::info("Created parent product", ['id' => $parentProduct->id, 'sku' => $currentSku]);
                    }
                } else {
                    $parentProduct = $existingParent;
                    Log::info("Found existing parent product", ['id' => $parentProduct->id, 'sku' => $currentSku]);
                }
                
                // Create Color and Size variations and add this row as the first variant
                if ($parentProduct) {
                    // Clear parent product images for variable type products
                    // Each variant should have its own images, parent should not have any
                    $parentProduct->files()->delete();
                    
                    $variantData = [
                        'attribute_color' => $attributeColor,
                        'attribute_size' => $attributeSize,
                        'sku' => $currentSku . '-' . Str::upper(Str::random(4)), // Variant gets different SKU
                        'price' => $data['price'] ?? null,
                        'qty' => $data['qty'] ?? 0,
                        'files' => $data['files'] ?? null, // Pass image files for variant
                    ];
                    $this->addVariantToParent($parentProduct, $variantData);
                    
                    // NOTE: Do NOT add images to parent product for variable type products
                    // Images are added only to individual variants via addVariantToParent
                }
                
                return; // Done processing parent row
            }
            
            // Handle VARIANT ROW (subsequent variants where SKU != parent_sku)
            if ($isVariant) {
                // Find parent product by SKU
                $parentProduct = Product::where('sku', $parentSku)->first();
                
                if (!$parentProduct) {
                    // Try case-insensitive search
                    $parentProduct = Product::whereRaw('LOWER(sku) = ?', [strtolower($parentSku)])->first();
                }
                
                if (!$parentProduct) {
                    // Parent doesn't exist yet - create it using this row's data
                    Log::info("Creating parent product from variant row", ['parent_sku' => $parentSku]);
                    
                    $parentData = $data;
                    $parentData['sku'] = $parentSku;
                    $parentData['type'] = 'variable'; // Parent is variable type
                    $parentData['slug'] = Str::slug($data['name'] ?? 'product') . '-' . Str::random(8);
                    
                    $parentProduct = Product::create($parentData);
                    
                    if ($parentProduct) {
                        // Sync categories and tags for newly created parent
                        if (!empty($categories)) {
                            $parentProduct->categories()->sync($categories);
                        }
                        if (!empty($tags)) {
                            $parentProduct->tags()->sync($tags);
                        }
                    }
                    
                    // NOTE: Do NOT add images to parent product for variable type products
                    // Images are added only to individual variants
                }
                
                if ($parentProduct) {
                    // This is a variant - add variation values to parent
                    $variantData = [
                        'attribute_color' => $attributeColor,
                        'attribute_size' => $attributeSize,
                        'sku' => $data['sku'] ?? null,
                        'price' => $data['price'] ?? null,
                        'qty' => $data['qty'] ?? 0,
                        'files' => $data['files'] ?? null, // Pass image files for variant
                    ];
                    $this->addVariantToParent($parentProduct, $variantData);
                    return; // Don't create separate product for variants
                }
            }

            request()->merge($data);
            $data['is_active'] = 1;
            $data['is_virtual'] = 1;
            
            // Check if product with same SKU already exists - UPDATE instead of CREATE
            $existingProduct = null;
            if (!empty($data['sku'])) {
                $existingProduct = Product::where('sku', $data['sku'])->first();
            }
            
            if ($existingProduct) {
                // ===== UPDATE EXISTING PRODUCT =====
                Log::info("Updating existing product", ['sku' => $data['sku'], 'id' => $existingProduct->id]);
                
                // Keep original slug to avoid URL changes
                unset($data['slug']);
                
                // Update product fields
                $existingProduct->update($data);
                $product = $existingProduct;
                
                // Update additional fields
                $product->barcode = $data['barcode'] ?? $product->barcode;
                $product->supplier_name = $data['supplier_name'] ?? $product->supplier_name;
                $product->supplier_code = $data['supplier_code'] ?? $product->supplier_code;
                $product->save();
                
            } else {
                // ===== CREATE NEW PRODUCT =====
                Log::info("Creating new product", ['sku' => $data['sku'] ?? 'N/A']);
                
                $product = Product::create($data);

                // Set fields that may not be in $fillable array
                if ($product) {
                    $product->barcode = $data['barcode'] ?? '';
                    $product->supplier_name = $data['supplier_name'] ?? '';
                    $product->supplier_code = $data['supplier_code'] ?? '';
                    $product->save();
                }
            }
            
            // Sync tags and categories (for both create and update)
            if ($product) {
                if (!empty($tags)) {
                    $product->tags()->sync($tags);
                }
                
                if (!empty($categories)) {
                    $product->categories()->sync($categories);
                }
                
                // Handle Color/Size variations
                $variationData = [
                    'attribute_color' => $attributeColor,
                    'attribute_size' => $attributeSize,
                    'price' => $data['price'] ?? null,
                    'qty' => $data['qty'] ?? 0,
                ];
                $this->handleAttributeVariations($product, $variationData);
            }
    
            if (!$product) {
                $this->errors->push(
                    'product',
                    sprintf(
                        "%s %s",
                        trans("importer::importer.write_to_database_failed"),
                        trans("importer::importer.product_not_created")
                    )
                );
            }

            if (request()->hasFile("images")) {
                $zipPath = request()
                    ->file("images")
                    ->getRealPath();

                $base = $this->processImage(
                    $zipPath,
                    $data["files"]["base_image"],
                    Product::class,
                    $product->id,
                    "base_image"
                );

                $addl = $this->processImage(
                    $zipPath,
                    $data["files"]["additional_images"],
                    Product::class,
                    $product->id,
                    "additional_images"
                );

                $imageUploadException = collect([]);
                $baseErrors = $base['error'];
                $addlErrors = $addl['error'];

                if ($baseErrors->isNotEmpty()) {
                    $imageUploadException->push('base images: ' . implode(", ", $baseErrors->toArray()));
                }

                if ($addlErrors->isNotEmpty()) {
                    $imageUploadException->push('additional images: ' . implode(", ", $addlErrors->toArray()));
                }

                $request = request();
                $cleaned = $request->all();
                unset($cleaned["files"]);
                $request->replace($cleaned);

                if ($imageUploadException->isNotEmpty()) {
                    throw new \RuntimeException(
                        "Image Processing failed: " . implode(", ", $imageUploadException->toArray())
                    );
                }
            }
            if (!empty($options)) {
                try {
                    // Process as VARIATIONS (not options)
                    $this->processVariations($product, $options);
                } catch (\Exception $e) {
                    $this->errors->push(
                        'product',
                        sprintf(
                            "Product SKU: %s - Variations Error: %s",
                            $row_data["sku"] ?? "Unknown",
                            $e->getMessage()
                        )
                    );
                }
            }

            if (!empty($attributes)) {
                $this->processAttributes($attributes, $product);
            }
        } catch (Exception $e) {
            
       
            $productSku = null;
            if (array_key_exists("sku", $row_data)) {
                
                
                $productSku = $row["sku"];
            }

            $this->errors->push(
                'product',
                sprintf(
                    "Product SKU: %s Error: %s at Row: %s",
                    $productSku,
                    $e->getMessage(),
                    $row->getIndex()
                )
            );
        }
    }




    private function normalize(array $data)
    {
        try {
            // Normalize product type
            $productType = strtolower(trim($data["type"] ?? "simple"));
            if ($productType === "varient") {
                $productType = "variant"; // Fix typo from client
            }
            if ($productType === "single") {
                $productType = "simple";
            }
            
            $test_arr = array_filter(
            [
                "name" => $data["name"],
                "sku" => $data["sku"] ?? null,
                "description" => $data["description"] ?? null,
                "short_description" => $data["short_description"] ?? null,
                "type" => $productType,
                "product_code" => $data["product_code"] ?? null,
                "key_features" => $data["key_features"] ?? null,
                "why_choose" => $data["why_choose"] ?? null,
                "tips_guide" => $data["tips_guide"] ?? null,
                "keywords" => $data["meta_keywords"] ?? null,
                "barcode" => $data["barcode"] ?? "",
                "technical_specs" => $data["technical_specs"] ?? "",
                "supplier_name" => $data["supplier_name"] ?? "",
                "supplier_code" => $data["supplier_code"] ?? "",
                "is_active" => $data["active"] ?? 1,
                "brand" => empty($data["brand"])
                    ? null
                    : $this->getOrCreateBrandByName($data["brand"])->id,
                "categories" => $this->mapExploded(
                    $data["categories"] ?? '',
                    function ($item) {
                        return $this->getOrCreateNestedCategory($item)->id;
                    }
                ),
                "tax_class_id" => $data["tax_class_id"] ?? null,
                "tags" => $this->mapExploded(
                    $data["tags"] ?? '',
                    function ($item) {
                        return $this->getOrCreateTagByName($item)->id;
                    },
                    ","
                ),
                "price" => $data["price"] ?? null,
                "meta_keywords" => $data["meta_keywords"] ?? null,
                "attribute_color" => $data["attribute_color"] ?? null,
                "attribute_size" => $data["attribute_size"] ?? null,
                "parent_sku" => $data["parent_sku"] ?? null,
                "special_price" => $data["special_price"] ?? null,
                "special_price_type" => $data["special_price_type"] ?? null,
                "special_price_start" => $data["special_price_start"] ?? null,
                "special_price_end" => $data["special_price_end"] ?? null,
                "manage_stock" => isset($data["manage_stock"]) && $data["manage_stock"] ? 1 : 0,
                "qty" => isset($data["quantity"]) ? (int)$data["quantity"] : 0,
                "in_stock" => isset($data["in_stock"]) ? (int)$data["in_stock"] : 0,
                "new_from" => $data["new_from"] ?? null,
                "new_to" => $data["new_to"] ?? null,
                "files" => $this->normalizeFiles($data),
                "meta" => $this->normalizeMetaData($data),
                'is_virtual' => isset($data['is_virtual']) ? (int)$data['is_virtual'] : 0,
                "attributes" => $data["attributes"] ?? '',
                "options" => $data["options"] ?? '',
            ],
            
            function ($value) {
                return $value !== null && $value !== false;
            }
        );


        
        // Ensure required database fields are always present
        $test_arr['barcode'] = $data["barcode"] ?? "";
        $test_arr['technical_specs'] = $data["technical_specs"] ?? "";
        $test_arr['supplier_name'] = $data["supplier_name"] ?? "";
        $test_arr['supplier_code'] = $data["supplier_code"] ?? "";
        
        return $test_arr;
        }
        catch (Exception $e) {
            // Re-throw the exception so it can be handled properly upstream
            throw $e;
        }
    }
    private function processAttributes($attributeString, $product)
    {
        $attributes = $this->parseAttributes($attributeString);
        $attributeIds = collect();
        foreach ($attributes as $attribute) {
            $attribute = (object)$attribute;
            $attributeSet = AttributeSet::whereHas("translations", function (
                $query
            ) use ($attribute) {
                $query->where("name", trim($attribute->attribute_set));
            })->first();

            if (!$attributeSet) {
                $attributeSet = AttributeSet::create([
                    "name" => trim($attribute->attribute_set),
                ]);
            }

            $entities = EntitiesAttribute::whereHas("translations", function (
                $query
            ) use ($attribute) {
                $query->where("name", $attribute->name);
            })->first();

            if (!$entities) {
                $entities = EntitiesAttribute::create([
                    "name" => $attribute->name,
                    "attribute_set_id" => $attributeSet->id,
                    "slug" => $attribute->slug,
                    "is_filterable" => $attribute->filterable ? 1 : 0,
                ]);
            }

            $entities->categories()->sync(
                $this->mapExploded(
                    implode(",", $attribute->categories),
                    function ($item) {
                        return $this->getOrCreateNestedCategory($item)->id;
                    }
                )
            );

            $productAttribute = ProductAttribute::create([
                "product_id" => $product->id,
                "attribute_id" => $entities->id,
            ]);

            $attributeValues = array_map(
                function ($value, $index) use ($entities, $productAttribute) {
                    $position = $index + 1;

                    // Try to find the attribute value by translation
                    $attributeValue = AttributeValue::where("attribute_id", $entities->id)
                        ->whereHas("translations", function ($query) use ($value) {
                            $query->where("value", $value);
                        })
                        ->first();

                    // Create it if it doesn't exist
                    if (!$attributeValue) {
                        $attributeValue = AttributeValue::create([
                            "value" => $value,
                            "attribute_id" => $entities->id,
                            "position" => $position,
                        ]);
                    }

                    return [
                        "product_attribute_id" => $productAttribute->id,
                        "attribute_value_id" => $attributeValue->id,
                    ];
                },
                $attribute->values,
                array_keys($attribute->values)
            );

            // Remove duplicate (product_attribute_id, attribute_value_id) pairs
            $attributeValues = collect($attributeValues)
                ->unique(function ($item) {
                    return $item['product_attribute_id'] . '-' . $item['attribute_value_id'];
                })
                ->values()
                ->all();

            ProductAttributeValue::insert($attributeValues);

            $attributeIds->push($entities->id);
        }
        return $attributeIds;
    }

    private function parseAttributes($string)
    {
        $attributeBlocks = array_map("trim", explode("||", $string));
        $parsed = [];

        foreach ($attributeBlocks as $block) {
            preg_match("/\[(.*?)\]/", $block, $setMatch);
            $attributeSet = $setMatch[1] ?? null;
            $block = preg_replace("/\[(.*?)\]\s*/", "", $block);
            $parts = array_map("trim", explode("|", $block));
            $data = [
                "attribute_set" => $attributeSet,
            ];

            foreach ($parts as $part) {
                if (str_contains($part, "Categories:")) {
                    $data["categories"] = array_map(
                        "trim",
                        explode(",", str_replace("Categories:", "", $part))
                    );
                } elseif (str_contains($part, "Slug:")) {
                    $data["slug"] = trim(str_replace("Slug:", "", $part));
                } elseif (str_contains($part, "Filterable:")) {
                    $data["filterable"] = trim(
                        str_replace("Filterable:", "", $part)
                    );
                } elseif (str_contains($part, "Values:")) {
                    $data["values"] = array_map(
                        "trim",
                        explode(",", str_replace("Values:", "", $part))
                    );
                } else {
                    $data["name"] = trim($part);
                }
            }

            $parsed[] = $data;
        }

        return $parsed;
    }

    private function explode($values)
    {
        if (trim($values) == "") {
            return false;
        }

        return array_map("trim", explode(",", $values));
    }

    /**
     * Explode images - supports both ; and , separators
     * Client uses ; for multiple images, we also support ,
     */
    private function explodeImages($values)
    {
        if (empty($values) || trim($values) == "") {
            return false;
        }
        
        // Detect separator - client uses ; for additional images
        $separator = str_contains($values, ';') ? ';' : ',';
        
        return array_map("trim", explode($separator, $values));
    }

    public function mapExploded(
        $string = "",
        callable $callback,
        string $delimiter = ","
    ): array {
        if (empty($string)) {
            return [];
        }
        return collect(explode($delimiter, $string))
            ->map(fn($item) => $callback(trim($item)))
            ->toArray();
    }

    private function normalizeFiles(array $data)
    {
        return [
            "base_image" => !empty($data["base_image"])
                ? $this->explodeImages($data["base_image"])
                : null,
            "additional_images" => !empty($data["additional_images"])
                ? $this->explodeImages($data["additional_images"])
                : null,
        ];
    }

    private function normalizeMetaData($data)
    {
        return [
            "meta_title" => $data["meta_title"] ?? null,
            "meta_description" => $data["meta_description"] ?? null,
        ];
    }

    private function getOrCreateNestedCategory(string $categoryPath): ?Category
    {
        if (blank($categoryPath)) {
            return null;
        }

        $categoryNames = array_filter(
            array_map("trim", explode("///", $categoryPath))
        );
        $locale = app()->getLocale();

        $parentId = null;
        $category = null;

        foreach ($categoryNames as $name) {
            if (empty($name)) {
                continue;
            }

            $category = Category::where("parent_id", $parentId)
                ->whereHas("translations", function ($query) use (
                    $name,
                    $locale
                ) {
                    $query->where("name", $name)->where("locale", $locale);
                })
                ->first();

            if (!$category) {
                $category = Category::create([
                    "name" => $name,
                    "parent_id" => $parentId,
                    "slug" => Str::slug($name),
                    "is_searchable" => false,
                    "is_active" => true,
                ]);
            }

            $parentId = $category->id;
        }

        return $category;
    }

    private function getOrCreateBrandByName($brandName)
    {
        $brand = Brand::whereHas("translations", function ($query) use (
            $brandName
        ) {
            $query->where("name", $brandName);
        })->first();

        if (!$brand) {
            $brand = Brand::create([
                "name" => $brandName,
                "is_active" => 1,
            ]);
        }

        return $brand;
    }

    private function getOrCreateTagByName($tagName)
    {
        $tagName = trim($tagName);
        $tag = Tag::whereHas("translations", function ($query) use ($tagName) {
            $query->where("name", $tagName);
        })->first();

        if (!$tag) {
            $tag = Tag::create([
                "name" => $tagName,
            ]);
        }
        return $tag;
    }

    private function processImage(
        $zipPath,
        $imagePaths,
        $entityType,
        $entityId,
        $zone
    ): array {
        if (empty($imagePaths)) {
            return [];
        }

        $zipBaseUri = "zip://{$zipPath}#";
        $successPaths = collect();
        $failedPaths = collect();
        $file_ids = collect();
        
        // Build a list of all files in the ZIP for case-insensitive matching
        $zipFileList = [];
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $zipFileList[strtolower($filename)] = $filename;
            }
            $zip->close();
        }

        foreach ($imagePaths as $imagePath) {
            $imageUri = "{$zipBaseUri}{$imagePath}";

            // Try to open the file directly from ZIP
            $fp = @fopen($imageUri, "rb");
            
            // If direct open fails, try case-insensitive matching
            if ($fp === false) {
                $lowerPath = strtolower($imagePath);
                if (isset($zipFileList[$lowerPath])) {
                    $actualPath = $zipFileList[$lowerPath];
                    $imageUri = "{$zipBaseUri}{$actualPath}";
                    $fp = @fopen($imageUri, "rb");
                    \Log::info("Case-insensitive image match found", [
                        'requested' => $imagePath,
                        'actual' => $actualPath
                    ]);
                }
            }
            
            if ($fp === false) {
                \Log::warning("Image not found in ZIP", ['path' => $imagePath]);
                $failedPaths->push($imagePath);
                continue;
            }
            
            $content = stream_get_contents($fp);
            fclose($fp);


            $original_filename = basename($imagePath);
            $extesnion = pathinfo($original_filename, PATHINFO_EXTENSION);
            $filename = implode(".", [Str::random(40), $extesnion]);
            $path = Storage::put("media/" . $filename, $content);

            if ($path) {
                $size = strlen($content);
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($content);
                $extension = pathinfo($original_filename, PATHINFO_EXTENSION);
                $file = File::create([
                    "user_id" => auth()->id(),
                    "disk" => config("filesystems.default"),
                    "filename" => substr($original_filename, 0, 255),
                    "path" => "media/{$filename}",
                    "extension" => $extension ?? "",
                    "mime" => $mimeType,
                    "size" => $size,
                ]);
                $file_ids->push($file->id);
                $successPaths->push($imagePaths);
            } else {
                $failedPaths->push($imagePaths);
            }

            $files = [
                $zone => $file_ids->toArray(),
            ];

            $this->syncFiles($files, $entityType, $entityId);
        }

        return [
            'success' => $successPaths,
            'error' => $failedPaths,
        ];
    }

    public function syncFiles(array $files = [], $entityType, $entityId): void
    {
        if (empty($files)) {
            return;
        }

        foreach ($files as $zone => $fileIds) {
            $syncList = [];

            foreach (array_wrap($fileIds) as $fileId) {
                if (!empty($fileId)) {
                    $syncList[$fileId]["zone"] = $zone;
                    $syncList[$fileId]["entity_type"] = $entityType;
                }
            }

            $this->filterFiles($zone, $entityType, $entityId)->detach();
            $this->filterFiles($zone, $entityType, $entityId)->attach(
                $syncList
            );
        }
    }

    public function filterFiles(
        string|array $zones,
        string       $entityType,
        int          $entity_id
    ) {
        $entity = app($entityType)::find($entity_id);

        if (!$entity) {
            $this->errors->push('product', "Entity not found for type {$entityType} with ID {$entity_id}");
        }

        return $entity->files()->wherePivotIn("zone", array_wrap($zones));
    }

    public function processVariations($product, string $variationsString): void
    {
        if (!$product) {
            $this->errors->push('product', "Product not found.");
            return;
        }

        $variationStrings = explode("||", $variationsString);
        $variationPosition = 1;

        foreach ($variationStrings as $variationString) {
            $variationString = trim($variationString);
            
            if (empty($variationString)) {
                continue;
            }
            
            $parts = explode(";", $variationString);
            $variationData = [];
            $values = [];

            foreach ($parts as $part) {
                $part = trim($part);
                
                if (empty($part)) {
                    continue;
                }
                
                if (preg_match('/^values\[(\d+)\]\[(.+)\]=(.+)$/', $part, $matches)) {
                    $index = $matches[1];
                    $key = $matches[2];
                    $value = trim($matches[3]);
                    $values[$index][$key] = $value;
                } elseif (strpos($part, "=") !== false) {
                    [$key, $value] = explode("=", $part, 2);
                    $variationData[trim($key)] = trim($value);
                }
            }

            // Create or get the variation
            $variationName = $variationData["name"] ?? "Unnamed Variation";
            $variationUid = Str::slug($variationName); // Convert to slug for uid
            
            $variation = Variation::firstOrCreate(
                ['uid' => $variationUid],
                [
                    'type' => $variationData["type"] ?? "dropdown",
                    'is_global' => 1,
                    'position' => $variationPosition,
                ]
            );
            
            // Set translation (models handle this automatically)
            if ($variation->wasRecentlyCreated || empty($variation->name)) {
                $variation->name = $variationName;
                $variation->save();
            }

            // Attach variation to product (pivot table has no extra columns)
            if (!$product->variations->contains($variation->id)) {
                $product->variations()->attach($variation->id);
            }

            // Create variation values
            $variationValuePosition = 1;
            $variationData["values"] = array_values($values);

            if (!empty($variationData["values"])) {
                // First, collect all the value texts from CSV to identify what should exist
                $csvValueTexts = [];
                foreach ($variationData["values"] as $valueData) {
                    $valueLabel = $valueData["label"] ?? "Unnamed";
                    $valueText = trim($valueData["value"] ?? $valueLabel);
                    $csvValueTexts[] = $valueText;
                }
                
                // Remove duplicate values from existing variation that are not in CSV
                // This prevents accumulation of duplicate values on re-import
                $existingValues = VariationValue::where('variation_id', $variation->id)->get();
                $existingValueTexts = $existingValues->pluck('value')->map(function($v) { return trim($v); })->toArray();
                
                // Find duplicates - values that appear more than once in existing
                $valueCounts = array_count_values($existingValueTexts);
                foreach ($existingValues as $existingValue) {
                    $trimmedValue = trim($existingValue->value);
                    // If this value appears more than once in DB, delete duplicates
                    if (isset($valueCounts[$trimmedValue]) && $valueCounts[$trimmedValue] > 1) {
                        $existingValue->delete();
                        $valueCounts[$trimmedValue]--; // Decrease count after deletion
                    }
                }
                
                // Now process CSV values
                foreach ($variationData["values"] as $valueData) {
                    $valueLabel = $valueData["label"] ?? "Unnamed";
                    // For color type, use hex code from 'value' field, otherwise use label
                    $valueText = trim($valueData["value"] ?? $valueLabel);
                    
                    // Extract price information if provided
                    $priceAmount = isset($valueData["price"]) ? floatval($valueData["price"]) : 0;
                    $priceType = $valueData["price_type"] ?? "fixed";
                    
                    // Generate random UID like the system expects (e.g., "j4qcltfini6q")
                    $valueUid = Str::random(12);
                    
                    // Check if value already exists by value OR label (via translations)
                    // First try by value field
                    $variationValue = VariationValue::where('variation_id', $variation->id)
                        ->where(function($query) use ($valueText, $valueLabel) {
                            $query->whereRaw('LOWER(COALESCE(value, "")) = ?', [strtolower($valueText)])
                                  ->orWhereHas('translations', function($q) use ($valueLabel) {
                                      $q->whereRaw('LOWER(label) = ?', [strtolower($valueLabel)]);
                                  });
                        })
                        ->first();
                    
                    // If not exists, create it
                    if (!$variationValue) {
                        $variationValue = new VariationValue();
                        $variationValue->variation_id = $variation->id;
                        $variationValue->uid = $valueUid;
                        $variationValue->position = $variationValuePosition;
                        
                        // Set the value (hex code for colors, label for others)
                        $variationValue->value = $valueText;
                        $variationValue->save();
                        
                        // Also try setting as translatable attribute
                        try {
                            $variationValue->label = $valueLabel;
                            $variationValue->save();
                        } catch (\Exception $e) {
                            // Label attribute might not exist, that's ok
                        }
                    } else {
                        // Update existing value if needed
                        $variationValue->position = $variationValuePosition;
                        // Only update value if it's currently empty
                        if (empty(trim($variationValue->value))) {
                            $variationValue->value = $valueText;
                        }
                        try {
                            $variationValue->label = $valueLabel;
                        } catch (\Exception $e) {
                            // Ignore if label doesn't exist
                        }
                        $variationValue->save();
                    }
                    
                    // Store price metadata in a temporary cache for variant generation
                    // We'll use this when creating product variants
                    cache()->put(
                        "variation_value_price_{$variationValue->id}",
                        ['price' => $priceAmount, 'price_type' => $priceType],
                        now()->addMinutes(10)
                    );

                    $variationValuePosition++;
                }
            }
            
            $variationPosition++;
        }
        
        // After creating all variations and their values, generate product variants
        $this->generateProductVariants($product);
    }

    public function normalizedOptions($product, string $optionsString): void
    {
        if (!$product) {
            $this->errors->push('product', "Product not found.");
        }

        $optionStrings = explode("||", $optionsString);
        $optionPosition = 1;
        $optionIds = collect();

        foreach ($optionStrings as $optionString) {
            $optionString = trim($optionString); // Remove leading/trailing whitespace
            
            if (empty($optionString)) {
                continue; // Skip empty option strings
            }
            
            $parts = explode(";", $optionString);
            $optionData = [];
            $values = [];

            foreach ($parts as $part) {
                $part = trim($part); // Trim each part
                
                if (empty($part)) {
                    continue;
                }
                
                if (
                    preg_match(
                        '/^values\[(\d+)\]\[(.+)\]=(.+)$/',
                        $part,
                        $matches
                    )
                ) {
                    $index = $matches[1];
                    $key = $matches[2];
                    $value = trim($matches[3]);
                    $values[$index][$key] = $value;
                } elseif (strpos($part, "=") !== false) {
                    [$key, $value] = explode("=", $part, 2);
                    $optionData[trim($key)] = trim($value);
                }
            }

            $optionData["values"] = array_values($values);
            $option = $product->options()->create([
                "name" => $optionData["name"] ?? "Unnamed Option",
                "type" => $optionData["type"] ?? "dropdown",
                "is_required" => $optionData["is_required"] ?? 0,
                "position" => $optionPosition,
                "is_global" => 1,
            ]);

            $optionIds->push($option->id);
            $optionValuePosition = 1;

            if (!empty($optionData["values"])) {
                foreach ($optionData["values"] as $valueData) {
                    $valueCreated = $option->values()->create([
                        "label" => $valueData["label"] ?? "Unnamed",
                        "price" => $valueData["price"] ?? 0,
                        "price_type" => $valueData["price_type"] ?? "fixed",
                        "position" => $optionValuePosition,
                        "option_id" => $option->id,
                    ]);

                    $optionValuePosition++;
                }
            } else {
                $valueCreated = $option->values()->create([
                    "label" => "Price",
                    "price" => 0,
                    "price_type" => "fixed",
                    "position" => 1,
                    "option_id" => $option->id,
                ]);
            }
            
            $optionPosition++; // Increment position for next option
        }

        $product->options()->sync($optionIds->toArray());
    }
    
    /**
     * Generate product variants from variations
     * Creates all combinations of variation values as product variants
     */
    private function generateProductVariants($product): void
    {
        // Reload product with variations and their values
        $product->load('variations.values');
        
        // Get all variations for this product
        $variations = $product->variations;
        
        if ($variations->isEmpty()) {
            return;
        }
        
        // Delete all existing variants for this product to avoid duplicates
        // This is safe for imports as we're regenerating all variants fresh
        ProductVariant::withoutGlobalScope('active')
            ->where('product_id', $product->id)
            ->forceDelete();
        
        // Get all variation values grouped by variation
        $variationValues = [];
        foreach ($variations as $variation) {
            $values = $variation->values;
            if ($values->isNotEmpty()) {
                $variationValues[] = $values->map(function($value) {
                    return [
                        'id' => $value->id,
                        'uid' => $value->uid,
                        'label' => $value->label ?? $value->value,
                        'variation_id' => $value->variation_id,
                    ];
                })->toArray();
            }
        }
        
        if (empty($variationValues)) {
            return;
        }
        
        // Generate all combinations (cartesian product)
        $combinations = $this->cartesianProduct($variationValues);
        
        // Create product variants for each combination
        $position = 1;
        foreach ($combinations as $combination) {
            // Build UIDs and name
            $uids = collect($combination)->pluck('uid')->sort()->join('.');
            $uid = md5($uids);
            $name = collect($combination)->pluck('label')->join(' / ');
            
            // Calculate variant price from variation values
            $variantPrice = $this->calculateVariantPrice($product, $combination);
            
            // Create new variant
            ProductVariant::create([
                'product_id' => $product->id,
                'uid' => $uid,
                'uids' => $uids,
                'name' => $name,
                'sku' => $product->sku . '-' . Str::upper(Str::random(6)),
                'price' => $variantPrice,
                'special_price' => $product->special_price ? $product->special_price->amount() : null,
                'special_price_type' => $product->special_price_type,
                'special_price_start' => $product->special_price_start,
                'special_price_end' => $product->special_price_end,
                'manage_stock' => $product->manage_stock ?? 0,
                'qty' => $product->qty ?? 0,
                'in_stock' => $product->in_stock ?? 1,
                'is_active' => 1,
                'is_default' => $position === 1, // First variant is default
                'position' => $position,
            ]);
            
            $position++;
        }
    }
    
    /**
     * Calculate variant price from variation value prices
     * 
     * @param Product $product
     * @param array $combination Array of variation values in this variant
     * @return float
     */
    private function calculateVariantPrice($product, array $combination): float
    {
        // Start with product base price
        $basePrice = $product->price->amount();
        $finalPrice = $basePrice;
        
        // Loop through each variation value in this combination
        foreach ($combination as $variationValue) {
            $variationValueId = $variationValue['id'];
            
            // Get cached price info for this variation value
            $priceInfo = cache()->get("variation_value_price_{$variationValueId}");
            
            if ($priceInfo && isset($priceInfo['price']) && $priceInfo['price'] > 0) {
                $priceAmount = floatval($priceInfo['price']);
                $priceType = $priceInfo['price_type'] ?? 'fixed';
                
                if ($priceType === 'fixed') {
                    // Add fixed amount to price
                    $finalPrice += $priceAmount;
                } elseif ($priceType === 'percent') {
                    // Add percentage of base price
                    $finalPrice += ($basePrice * ($priceAmount / 100));
                }
            }
        }
        
        return $finalPrice;
    }
    
    /**
     * Generate cartesian product of arrays
     * Used to create all combinations of variation values
     */
    private function cartesianProduct($arrays): array
    {
        $result = [[]];
        
        foreach ($arrays as $key => $values) {
            $append = [];
            
            foreach ($result as $product) {
                foreach ($values as $item) {
                    $product[$key] = $item;
                    $append[] = $product;
                }
            }
            
            $result = $append;
        }
        
        return $result;
    }

    /**
     * Add variant to parent product
     * Links variant row to existing parent product
     */
    private function addVariantToParent($parentProduct, array $data): void
    {
        try {
            $colorValue = trim($data['attribute_color'] ?? '');
            $sizeValue = trim($data['attribute_size'] ?? '');
            $variantSku = $data['sku'] ?? null;
            
            // Handle price - could be numeric or Money object
            $variantPrice = $data['price'] ?? null;
            if ($variantPrice === null) {
                // Fallback to parent product price
                $parentPrice = $parentProduct->price;
                $variantPrice = is_object($parentPrice) ? $parentPrice->amount() : $parentPrice;
            }
            // Ensure it's numeric
            $variantPrice = is_object($variantPrice) ? $variantPrice->amount() : $variantPrice;
            
            $variantQty = $data['qty'] ?? 0;
            
            \Log::info("=== ADDING VARIANT TO PARENT ===", [
                'parent_id' => $parentProduct->id,
                'parent_sku' => $parentProduct->sku,
                'color' => $colorValue,
                'size' => $sizeValue,
                'variant_sku' => $variantSku,
                'variant_price' => $variantPrice,
                'variant_qty' => $variantQty
            ]);
            
            // Reload product variations to ensure fresh data
            $parentProduct->load('variations');
            
            $colorVariation = null;
            $sizeVariation = null;
            
            // Track which values we're importing for this product
            // This is used to remove old values that are no longer in the import
            static $productImportedColors = [];
            static $productImportedSizes = [];
            
            $productId = $parentProduct->id;
            
            // Initialize tracking arrays for this product if not exists
            if (!isset($productImportedColors[$productId])) {
                $productImportedColors[$productId] = [];
            }
            if (!isset($productImportedSizes[$productId])) {
                $productImportedSizes[$productId] = [];
            }
            
            // Get existing LOCAL variations for this product
            $existingVariations = $parentProduct->variations()->where('is_global', false)->get();
            $existingColorVar = $existingVariations->where('uid', 'like', 'color-%')->first();
            $existingColorVar = $existingColorVar ?? $existingVariations->filter(function($v) {
                return stripos($v->name, 'color') !== false;
            })->first();
            
            $existingSizeVar = $existingVariations->where('uid', 'like', 'size-%')->first();
            $existingSizeVar = $existingSizeVar ?? $existingVariations->filter(function($v) {
                return stripos($v->name, 'size') !== false;
            })->first();
            
            // Create or get LOCAL Color variation for this product
            if (!empty($colorValue)) {
                \Log::info("Processing Color variation (LOCAL)", ['color' => $colorValue]);
                
                if (!$existingColorVar) {
                    // Create LOCAL variation for this product
                    $colorVariation = $this->createLocalVariationForProduct($parentProduct, 'Color', 'color');
                    
                    // New variation - no old values to worry about
                } else {
                    $colorVariation = $existingColorVar;
                    
                    // If this is the first color for this import session, clear old values
                    if (empty($productImportedColors[$productId])) {
                        \Log::info("Clearing old color values for re-import", ['variation_id' => $colorVariation->id]);
                        VariationValue::where('variation_id', $colorVariation->id)->delete();
                        
                        // Also clear old product variants
                        ProductVariant::where('product_id', $productId)->delete();
                    }
                }
                
                \Log::info("Got Color variation", ['id' => $colorVariation->id, 'uid' => $colorVariation->uid, 'is_global' => $colorVariation->is_global]);
                
                // Track this color
                $productImportedColors[$productId][] = strtolower($colorValue);
                
                // Add value to this LOCAL variation
                $this->addVariationValueToLocalVariation($colorVariation, $colorValue);
            }
            
            // Create or get LOCAL Size variation for this product
            if (!empty($sizeValue)) {
                \Log::info("Processing Size variation (LOCAL)", ['size' => $sizeValue]);
                
                if (!$existingSizeVar) {
                    // Create LOCAL variation for this product
                    $sizeVariation = $this->createLocalVariationForProduct($parentProduct, 'Size', 'text');
                    
                    // New variation - no old values to worry about
                } else {
                    $sizeVariation = $existingSizeVar;
                    
                    // If this is the first size for this import session, clear old values
                    if (empty($productImportedSizes[$productId])) {
                        \Log::info("Clearing old size values for re-import", ['variation_id' => $sizeVariation->id]);
                        VariationValue::where('variation_id', $sizeVariation->id)->delete();
                    }
                }
                
                \Log::info("Got Size variation", ['id' => $sizeVariation->id, 'uid' => $sizeVariation->uid, 'is_global' => $sizeVariation->is_global]);
                
                // Track this size
                $productImportedSizes[$productId][] = strtolower($sizeValue);
                
                // Add value to this LOCAL variation
                $this->addVariationValueToLocalVariation($sizeVariation, $sizeValue);
            }
            
            // Reload variations after adding
            $parentProduct->load('variations.values');
            
            // Log final state
            \Log::info("Product variations after processing", [
                'product_id' => $parentProduct->id,
                'variations_count' => $parentProduct->variations->count(),
                'variation_names' => $parentProduct->variations->pluck('name')->toArray()
            ]);
        
        // Create product variant record
        if (!empty($colorValue) || !empty($sizeValue)) {
            $variantName = '';
            if (!empty($colorValue) && !empty($sizeValue)) {
                $variantName = $colorValue . ' / ' . $sizeValue;
            } elseif (!empty($colorValue)) {
                $variantName = $colorValue;
            } else {
                $variantName = $sizeValue;
            }
            
            // Build proper UIDs from LOCAL VariationValue UIDs (required for frontend price display)
            $variationValueUids = [];
            
            // Use the local variations we created/found earlier
            if (!empty($colorValue) && $colorVariation) {
                $colorVarValue = VariationValue::where('variation_id', $colorVariation->id)
                    ->where(function($query) use ($colorValue) {
                        $query->whereRaw('LOWER(COALESCE(value, "")) = ?', [strtolower($this->colorNameToHex($colorValue))])
                              ->orWhereHas('translations', function($q) use ($colorValue) {
                                  $q->whereRaw('LOWER(label) = ?', [strtolower($colorValue)]);
                              });
                    })
                    ->first();
                    
                if ($colorVarValue) {
                    $variationValueUids[] = $colorVarValue->uid;
                    \Log::info("Found color variation value for UID", ['uid' => $colorVarValue->uid, 'label' => $colorValue]);
                }
            }
            
            if (!empty($sizeValue) && $sizeVariation) {
                $sizeVarValue = VariationValue::where('variation_id', $sizeVariation->id)
                    ->where(function($query) use ($sizeValue) {
                        $query->whereRaw('LOWER(COALESCE(value, "")) = ?', [strtolower($sizeValue)])
                              ->orWhereHas('translations', function($q) use ($sizeValue) {
                                  $q->whereRaw('LOWER(label) = ?', [strtolower($sizeValue)]);
                              });
                    })
                    ->first();
                    
                if ($sizeVarValue) {
                    $variationValueUids[] = $sizeVarValue->uid;
                    \Log::info("Found size variation value for UID", ['uid' => $sizeVarValue->uid, 'label' => $sizeValue]);
                }
            }
            
            // Sort and join UIDs (frontend expects this format)
            sort($variationValueUids);
            $uidsString = implode('.', $variationValueUids);
            $uid = md5($uidsString);
            
            // Check if variant already exists
            $existingVariant = ProductVariant::where('product_id', $parentProduct->id)
                ->where('name', $variantName)
                ->first();
                
            if ($existingVariant) {
                // UPDATE existing variant with new values
                $existingVariant->update([
                    'sku' => $variantSku ?? $existingVariant->sku,
                    'price' => $variantPrice,
                    'qty' => $variantQty,
                    'in_stock' => $variantQty > 0 ? 1 : 0,
                ]);
                
                \Log::info("Updated existing product variant", [
                    'variant_id' => $existingVariant->id,
                    'variant_name' => $variantName,
                    'new_sku' => $variantSku,
                    'new_price' => $variantPrice,
                    'parent_id' => $parentProduct->id
                ]);
                
                // Update images for existing variant
                $variantFiles = $data['files'] ?? null;
                if (request()->hasFile("images") && !empty($variantFiles)) {
                    $zipPath = request()->file("images")->getRealPath();
                    
                    $this->processImage(
                        $zipPath,
                        $variantFiles["base_image"] ?? [],
                        ProductVariant::class,
                        $existingVariant->id,
                        "base_image"
                    );

                    $this->processImage(
                        $zipPath,
                        $variantFiles["additional_images"] ?? [],
                        ProductVariant::class,
                        $existingVariant->id,
                        "additional_images"
                    );
                }
            } else {
                // Check if this will be the first variant (should be default)
                $existingVariantCount = ProductVariant::where('product_id', $parentProduct->id)->count();
                $isFirstVariant = $existingVariantCount === 0;
                
                // CREATE new variant
                $variant = ProductVariant::create([
                    'product_id' => $parentProduct->id,
                    'uid' => $uid,
                    'uids' => $uidsString,
                    'name' => $variantName,
                    'sku' => $variantSku ?? $parentProduct->sku . '-' . Str::upper(Str::random(6)),
                    'price' => $variantPrice,
                    'manage_stock' => $parentProduct->manage_stock ?? 0,
                    'qty' => $variantQty,
                    'in_stock' => $variantQty > 0 ? 1 : 0,
                    'is_active' => 1,
                    'is_default' => $isFirstVariant ? 1 : 0,
                    'position' => $existingVariantCount + 1,
                ]);
                
                \Log::info("Created product variant", [
                    'variant_id' => $variant->id,
                    'variant_name' => $variantName,
                    'uids' => $uidsString,
                    'is_default' => $isFirstVariant,
                    'parent_id' => $parentProduct->id
                ]);
                
                // Process images for this variant
                $variantFiles = $data['files'] ?? null;
                if (request()->hasFile("images") && !empty($variantFiles)) {
                    $zipPath = request()->file("images")->getRealPath();
                    
                    \Log::info("Processing images for variant", [
                        'variant_id' => $variant->id,
                        'base_image' => $variantFiles['base_image'] ?? 'none'
                    ]);
                    
                    $this->processImage(
                        $zipPath,
                        $variantFiles["base_image"] ?? [],
                        ProductVariant::class,
                        $variant->id,
                        "base_image"
                    );

                    $this->processImage(
                        $zipPath,
                        $variantFiles["additional_images"] ?? [],
                        ProductVariant::class,
                        $variant->id,
                        "additional_images"
                    );
                }
            }
        }
        
        } catch (\Exception $e) {
            \Log::error("=== ERROR IN addVariantToParent ===", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Re-throw to not silently fail
        }
    }

    /**
     * Handle attribute variations for standalone products
     * Creates LOCAL variations from Attribute:Color and Attribute:Size columns
     */
    private function handleAttributeVariations($product, array $data): void
    {
        $colorValue = trim($data['attribute_color'] ?? '');
        $sizeValue = trim($data['attribute_size'] ?? '');
        
        if (empty($colorValue) && empty($sizeValue)) {
            return;
        }
        
        // Get existing LOCAL variations for this product
        $existingVariations = $product->variations()->where('is_global', false)->get();
        $existingColorVar = $existingVariations->filter(function($v) {
            return stripos($v->name, 'color') !== false;
        })->first();
        $existingSizeVar = $existingVariations->filter(function($v) {
            return stripos($v->name, 'size') !== false;
        })->first();
        
        $colorVariation = null;
        $sizeVariation = null;
        
        // Create LOCAL Color variation if present
        if (!empty($colorValue)) {
            if (!$existingColorVar) {
                $colorVariation = $this->createLocalVariationForProduct($product, 'Color', 'color');
            } else {
                $colorVariation = $existingColorVar;
            }
            $this->addVariationValueToLocalVariation($colorVariation, $colorValue);
        }
        
        // Create LOCAL Size variation if present
        if (!empty($sizeValue)) {
            if (!$existingSizeVar) {
                $sizeVariation = $this->createLocalVariationForProduct($product, 'Size', 'text');
            } else {
                $sizeVariation = $existingSizeVar;
            }
            $this->addVariationValueToLocalVariation($sizeVariation, $sizeValue);
        }
        
        // Create product variant for this product's attribute values
        if (!empty($colorValue) || !empty($sizeValue)) {
            $variantName = '';
            if (!empty($colorValue) && !empty($sizeValue)) {
                $variantName = $colorValue . ' / ' . $sizeValue;
            } elseif (!empty($colorValue)) {
                $variantName = $colorValue;
            } else {
                $variantName = $sizeValue;
            }
            
            // Build proper UIDs from LOCAL VariationValue UIDs
            $variationValueUids = [];
            
            if (!empty($colorValue) && $colorVariation) {
                $colorVarValue = VariationValue::where('variation_id', $colorVariation->id)
                    ->where(function($query) use ($colorValue) {
                        $query->whereRaw('LOWER(COALESCE(value, "")) = ?', [strtolower($this->colorNameToHex($colorValue))])
                              ->orWhereHas('translations', function($q) use ($colorValue) {
                                  $q->whereRaw('LOWER(label) = ?', [strtolower($colorValue)]);
                              });
                    })
                    ->first();
                if ($colorVarValue) {
                    $variationValueUids[] = $colorVarValue->uid;
                }
            }
            
            if (!empty($sizeValue) && $sizeVariation) {
                $sizeVarValue = VariationValue::where('variation_id', $sizeVariation->id)
                    ->where(function($query) use ($sizeValue) {
                        $query->whereRaw('LOWER(COALESCE(value, "")) = ?', [strtolower($sizeValue)])
                              ->orWhereHas('translations', function($q) use ($sizeValue) {
                                  $q->whereRaw('LOWER(label) = ?', [strtolower($sizeValue)]);
                              });
                    })
                    ->first();
                if ($sizeVarValue) {
                    $variationValueUids[] = $sizeVarValue->uid;
                }
            }
            
            // Sort and join UIDs (frontend expects this format)
            sort($variationValueUids);
            $uidsString = implode('.', $variationValueUids);
            $uid = md5($uidsString);
            
            // Check if variant already exists
            $existingVariant = ProductVariant::where('product_id', $product->id)
                ->where('name', $variantName)
                ->first();
            
            // Handle price - ensure it's numeric
            $variantPrice = $data['price'] ?? null;
            if ($variantPrice === null) {
                $parentPrice = $product->price;
                $variantPrice = is_object($parentPrice) ? $parentPrice->amount() : $parentPrice;
            }
            $variantPrice = is_object($variantPrice) ? $variantPrice->amount() : $variantPrice;
                
            if (!$existingVariant) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'uid' => $uid,
                    'uids' => $uidsString,
                    'name' => $variantName,
                    'sku' => $product->sku . '-' . Str::upper(Str::random(6)),
                    'price' => $variantPrice,
                    'manage_stock' => $product->manage_stock ?? 0,
                    'qty' => $data['qty'] ?? 0,
                    'in_stock' => ($data['qty'] ?? 0) > 0 ? 1 : 0,
                    'is_active' => 1,
                    'is_default' => 1, // First variant is default
                    'position' => 1,
                ]);
            }
        }
    }

    /**
     * Create a LOCAL variation for a specific product
     * Local variations (is_global=0) are product-specific
     */
    private function createLocalVariationForProduct($product, string $name, string $type): Variation
    {
        // Generate unique UID for this product's variation
        $uid = strtolower($name) . '-' . $product->id . '-' . Str::random(6);
        
        Log::info("Creating LOCAL variation for product", [
            'product_id' => $product->id,
            'name' => $name,
            'type' => $type,
            'uid' => $uid
        ]);
        
        // Create new LOCAL variation
        $variation = new Variation();
        $variation->uid = $uid;
        $variation->type = $type;
        $variation->is_global = 0; // LOCAL - product specific
        $variation->position = $product->variations()->count() + 1;
        $variation->save();
        
        // Set translatable name
        try {
            $variation->name = $name;
            $variation->save();
        } catch (\Exception $e) {
            Log::warning("Could not set variation name", ['error' => $e->getMessage()]);
        }
        
        // Attach to product
        $product->variations()->attach($variation->id);
        
        Log::info("Created and attached LOCAL variation", [
            'variation_id' => $variation->id,
            'product_id' => $product->id
        ]);
        
        return $variation;
    }
    
    /**
     * Add a value to a LOCAL variation
     * Only adds if value doesn't already exist
     */
    private function addVariationValueToLocalVariation(Variation $variation, string $value): ?VariationValue
    {
        $value = trim($value);
        if (empty($value)) {
            return null;
        }
        
        Log::info("Adding value to LOCAL variation", [
            'variation_id' => $variation->id,
            'variation_name' => $variation->name,
            'value' => $value
        ]);
        
        // Check if value already exists (by label in translations)
        $existingValue = VariationValue::where('variation_id', $variation->id)
            ->where(function($query) use ($value) {
                $query->whereRaw('LOWER(COALESCE(value, "")) = ?', [strtolower($value)])
                      ->orWhereHas('translations', function($q) use ($value) {
                          $q->whereRaw('LOWER(label) = ?', [strtolower($value)]);
                      });
            })
            ->first();
        
        if ($existingValue) {
            Log::info("Value already exists in variation", [
                'existing_id' => $existingValue->id,
                'value' => $value
            ]);
            return $existingValue;
        }
        
        // Create new value
        $variationValue = new VariationValue();
        $variationValue->variation_id = $variation->id;
        $variationValue->uid = Str::random(12);
        $variationValue->position = VariationValue::where('variation_id', $variation->id)->count() + 1;
        
        // For Color type, convert to hex; for others use as-is
        if ($variation->type === 'color') {
            $variationValue->value = $this->colorNameToHex($value);
        } else {
            $variationValue->value = $value;
        }
        
        $variationValue->save();
        
        // Set label (translatable)
        try {
            $variationValue->label = $value;
            $variationValue->save();
        } catch (\Exception $e) {
            Log::warning("Could not set variation value label", ['error' => $e->getMessage()]);
        }
        
        Log::info("Created new variation value", [
            'id' => $variationValue->id,
            'uid' => $variationValue->uid,
            'label' => $value,
            'color_hex' => $variationValue->value
        ]);
        
        return $variationValue;
    }
    
    /**
     * Get or create a variation by name (GLOBAL - for backwards compatibility)
     */
    private function getOrCreateVariation(string $name, string $type): Variation
    {
        $uid = Str::slug($name);
        
        Log::info("getOrCreateVariation called", ['name' => $name, 'uid' => $uid, 'type' => $type]);
        
        // First try to find existing (include soft-deleted!)
        $variation = Variation::withTrashed()->where('uid', $uid)->first();
        
        // If found but soft-deleted, restore it
        if ($variation && $variation->trashed()) {
            Log::info("Restoring soft-deleted variation", ['id' => $variation->id, 'uid' => $uid]);
            $variation->restore();
        }
        
        Log::info("Existing variation check", ['found' => $variation ? 'YES (ID: '.$variation->id.')' : 'NO', 'uid' => $uid]);
        
        if (!$variation) {
            // Try to create, catch duplicate error if another process created it
            try {
                $variation = new Variation();
                $variation->uid = $uid;
                $variation->type = $type;
                $variation->is_global = 1;
                $variation->position = Variation::count() + 1;
                $variation->save();
                
                Log::info("Created new variation", ['id' => $variation->id, 'uid' => $uid]);
                
                // Set translatable name
                try {
                    $variation->name = $name;
                    $variation->save();
                } catch (\Exception $e) {
                    Log::info("Could not set variation name", ['error' => $e->getMessage()]);
                }
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                Log::info("Duplicate error, fetching existing", ['uid' => $uid]);
                // Another process created it, fetch it
                $variation = Variation::where('uid', $uid)->first();
            } catch (\Exception $e) {
                Log::error("Error creating variation", ['uid' => $uid, 'error' => $e->getMessage()]);
                // If still fails, try one more time to fetch
                $variation = Variation::where('uid', $uid)->first();
            }
        }
        
        // If still null, something is wrong - throw exception
        if (!$variation) {
            throw new \Exception("Could not get or create variation with uid: {$uid}");
        }
        
        // Update type if different (e.g., Size should be 'text' not 'color')
        if ($variation->type !== $type) {
            $variation->type = $type;
            $variation->save();
            Log::info("Updated variation type", ['id' => $variation->id, 'new_type' => $type]);
        }
        
        return $variation;
    }

    /**
     * Add variation value to product
     */
    private function addVariationValueToProduct($product, Variation $variation, string $value): void
    {
        // Skip empty values
        $value = trim($value);
        if (empty($value)) {
            return;
        }
        
        Log::info("addVariationValueToProduct called", [
            'product_id' => $product->id,
            'variation_id' => $variation->id,
            'variation_uid' => $variation->uid,
            'value' => $value
        ]);
        
        // Check if value already exists by value OR label (case-insensitive)
        // Size variations have empty 'value' but 'label' contains S, M, L, XL
        // Color variations have both 'value' and 'label' set
        $existingValue = VariationValue::where('variation_id', $variation->id)
            ->where(function($query) use ($value) {
                $query->whereRaw('LOWER(COALESCE(value, "")) = ?', [strtolower($value)])
                      ->orWhereHas('translations', function($q) use ($value) {
                          $q->whereRaw('LOWER(label) = ?', [strtolower($value)]);
                      });
            })
            ->first();
        
        if ($existingValue) {
            Log::info("Variation value already exists", [
                'id' => $existingValue->id,
                'uid' => $existingValue->uid,
                'variation_id' => $variation->id,
                'value' => $value
            ]);
            return;
        }
        
        // Create new variation value
        $variationValue = new VariationValue();
        $variationValue->variation_id = $variation->id;
        $variationValue->uid = Str::random(12);
        $variationValue->position = VariationValue::where('variation_id', $variation->id)->count() + 1;
        
        // For Color variation, convert color name to hex code
        // For other variations (Size), use the value as-is
        if ($variation->uid === 'color' || $variation->type === 'color') {
            $variationValue->value = $this->colorNameToHex($value);
        } else {
            $variationValue->value = $value;
        }
        
        $variationValue->save();
        
        Log::info("Created new VariationValue", [
            'id' => $variationValue->id,
            'uid' => $variationValue->uid,
            'variation_id' => $variation->id,
            'value' => $variationValue->value
        ]);
        
        // Set label (translatable field) - always the color/size name
        try {
            $variationValue->label = $value;
            $variationValue->save();
            Log::info("Set VariationValue label", ['label' => $value]);
        } catch (\Exception $e) {
            Log::warning("Could not set variation value label", ['value' => $value, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Convert color name to hex code
     */
    private function colorNameToHex(string $colorName): string
    {
        $colors = [
            'red' => '#ff0000',
            'blue' => '#0000ff',
            'green' => '#00ff00',
            'black' => '#000000',
            'white' => '#ffffff',
            'yellow' => '#ffff00',
            'orange' => '#ffa500',
            'purple' => '#800080',
            'pink' => '#ffc0cb',
            'brown' => '#a52a2a',
            'gray' => '#808080',
            'grey' => '#808080',
            'navy' => '#000080',
            'teal' => '#008080',
            'maroon' => '#800000',
            'olive' => '#808000',
            'aqua' => '#00ffff',
            'cyan' => '#00ffff',
            'silver' => '#c0c0c0',
            'gold' => '#ffd700',
            'beige' => '#f5f5dc',
            'coral' => '#ff7f50',
            'crimson' => '#dc143c',
            'indigo' => '#4b0082',
            'khaki' => '#f0e68c',
            'lime' => '#00ff00',
            'magenta' => '#ff00ff',
            'violet' => '#ee82ee',
            'tan' => '#d2b48c',
            'turquoise' => '#40e0d0',
        ];
        
        $lowerColor = strtolower(trim($colorName));
        
        // If it's already a hex code, return as-is
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $colorName)) {
            return $colorName;
        }
        
        // Return mapped hex code or default to the color name
        return $colors[$lowerColor] ?? $colorName;
    }
}

