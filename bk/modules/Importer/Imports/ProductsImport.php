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
use Maatwebsite\Excel\Row;
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
        
        // First, save the lowercase 'sku' column value (parent SKU) before it gets overwritten
        $parentSku = null;
        foreach ($data as $key => $value) {
            if (trim($key) === 'sku' && !empty($value)) {
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
        
        foreach ($data as $key => $value) {
            // Trim the key to remove any trailing/leading spaces
            $trimmedKey = trim($key);
            
            // Skip the lowercase 'sku' column - we handle it separately as parent_sku
            if ($trimmedKey === 'sku') {
                continue;
            }
            
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
        
        // Handle SKU - CSV parser may have merged the two SKU columns
        // If sku is not set, use product_code as fallback
        if (empty($normalized['sku']) && !empty($normalized['product_code'])) {
            $normalized['sku'] = $normalized['product_code'];
        }
        
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
            
            // Only consider it a variant if it has a parent_sku (from lowercase 'sku' column)
            // The first variant row (without parent_sku) will become the parent product
            $isVariant = !empty($parentSku) && ($productType === 'variant' || $productType === 'varient');
            
            Log::info("=== Row Processing ===", [
                'product_sku' => $data['sku'] ?? 'N/A',
                'parent_sku' => $parentSku,
                'is_variant' => $isVariant,
                'type' => $data['type'] ?? 'N/A',
                'attribute_color' => $attributeColor,
                'attribute_size' => $attributeSize,
            ]);
            
            if ($isVariant) {
                // Find parent product by SKU
                $parentProduct = Product::where('sku', $parentSku)->first();
                
                if (!$parentProduct) {
                    // Try case-insensitive search
                    $parentProduct = Product::whereRaw('LOWER(sku) = ?', [strtolower($parentSku)])->first();
                }
                
                if (!$parentProduct) {
                    // Parent doesn't exist yet - create it using this row's data
                    Log::info("Creating parent product", ['parent_sku' => $parentSku]);
                    
                    $parentData = $data;
                    $parentData['sku'] = $parentSku;
                    $parentData['type'] = 'variable'; // Parent is variable type
                    $parentData['slug'] = Str::slug($data['name'] ?? 'product') . '-' . Str::random(8);
                    
                    $parentProduct = Product::create($parentData);
                    
                    // Handle variations for parent (create Color and Size variations)
                    $this->handleAttributeVariations($parentProduct, [
                        'attribute_color' => $attributeColor,
                        'attribute_size' => $attributeSize,
                        'price' => $data['price'] ?? null,
                        'qty' => $data['qty'] ?? 0,
                    ]);
                }
                
                if ($parentProduct) {
                    // This is a variant - add variation values to parent
                    $variantData = [
                        'attribute_color' => $attributeColor,
                        'attribute_size' => $attributeSize,
                        'sku' => $data['sku'] ?? null,
                        'price' => $data['price'] ?? null,
                        'qty' => $data['qty'] ?? 0,
                    ];
                    $this->addVariantToParent($parentProduct, $variantData);
                    return; // Don't create separate product for variants
                }
            }

            request()->merge($data);
            $data['is_active'] = 1;
            $data['is_virtual'] = 1;
            $product = Product::create($data);

            // Set fields that may not be in $fillable array
            if ($product) {
                $product->barcode = $data['barcode'] ?? '';
                $product->supplier_name = $data['supplier_name'] ?? '';
                $product->supplier_code = $data['supplier_code'] ?? '';
                $product->save();
                
                // Sync tags and categories via relationships
                if (!empty($tags)) {
                    $product->tags()->sync($tags);
                }
                
                if (!empty($categories)) {
                    $product->categories()->sync($categories);
                }
                
                // Option C: Each product gets its own Color/Size variations
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

        foreach ($imagePaths as $imagePath) {
            $imageUri = "{$zipBaseUri}{$imagePath}";

            // Try to open the file directly from ZIP
            $fp = @fopen($imageUri, "rb");
            
            if ($fp === false) {
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
                    
                    // Check if value already exists by BOTH value and label to handle duplicates properly
                    $variationValue = VariationValue::where('variation_id', $variation->id)
                        ->get()
                        ->first(function($v) use ($valueText, $valueLabel) {
                            $vValue = trim($v->value);
                            $vLabel = trim($v->label);
                            
                            // Match by value if non-empty, otherwise match by label
                            if (!empty($vValue)) {
                                return $vValue === $valueText;
                            } else {
                                return $vLabel === trim($valueLabel);
                            }
                        });
                    
                    // If not exists, create it
                    if (!$variationValue) {
                        // Before creating, check if there's an old entry with same label but empty value
                        $oldEmptyValue = VariationValue::where('variation_id', $variation->id)
                            ->get()
                            ->first(function($v) use ($valueLabel) {
                                return trim($v->label) === trim($valueLabel) && empty(trim($v->value));
                            });
                        
                        // If found, delete it to avoid duplicates
                        if ($oldEmptyValue) {
                            $oldEmptyValue->delete();
                        }
                        
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
        $colorValue = trim($data['attribute_color'] ?? '');
        $sizeValue = trim($data['attribute_size'] ?? '');
        $variantSku = $data['sku'] ?? null;
        $variantPrice = $data['price'] ?? $parentProduct->price;
        $variantQty = $data['qty'] ?? 0;
        
        \Log::info("Adding variant to parent", [
            'parent_id' => $parentProduct->id,
            'parent_sku' => $parentProduct->sku,
            'color' => $colorValue,
            'size' => $sizeValue,
            'variant_sku' => $variantSku
        ]);
        
        // Create or get Color variation and attach to product
        if (!empty($colorValue)) {
            $colorVariation = $this->getOrCreateVariation('Color', 'color');
            $this->addVariationValueToProduct($parentProduct, $colorVariation, $colorValue);
            
            // Attach variation to product if not already attached
            if (!$parentProduct->variations->contains($colorVariation->id)) {
                $parentProduct->variations()->attach($colorVariation->id);
                $parentProduct->load('variations'); // Reload
            }
        }
        
        // Create or get Size variation and attach to product
        if (!empty($sizeValue)) {
            $sizeVariation = $this->getOrCreateVariation('Size', 'text');
            $this->addVariationValueToProduct($parentProduct, $sizeVariation, $sizeValue);
            
            // Attach variation to product if not already attached
            if (!$parentProduct->variations->contains($sizeVariation->id)) {
                $parentProduct->variations()->attach($sizeVariation->id);
                $parentProduct->load('variations'); // Reload
            }
        }
        
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
            
            $uid = md5($parentProduct->id . '-' . $variantName);
            
            // Check if variant already exists
            $existingVariant = ProductVariant::where('product_id', $parentProduct->id)
                ->where('name', $variantName)
                ->first();
                
            if (!$existingVariant) {
                $variant = ProductVariant::create([
                    'product_id' => $parentProduct->id,
                    'uid' => $uid,
                    'uids' => Str::slug($variantName),
                    'name' => $variantName,
                    'sku' => $variantSku ?? $parentProduct->sku . '-' . Str::upper(Str::random(6)),
                    'price' => $variantPrice,
                    'manage_stock' => $parentProduct->manage_stock ?? 0,
                    'qty' => $variantQty,
                    'in_stock' => $variantQty > 0 ? 1 : 0,
                    'is_active' => 1,
                    'position' => ProductVariant::where('product_id', $parentProduct->id)->count() + 1,
                ]);
                
                \Log::info("Created product variant", [
                    'variant_id' => $variant->id,
                    'variant_name' => $variantName,
                    'parent_id' => $parentProduct->id
                ]);
            }
        }
    }

    /**
     * Handle attribute variations for standalone products
     * Creates variations from Attribute:Color and Attribute:Size columns
     */
    private function handleAttributeVariations($product, array $data): void
    {
        $colorValue = trim($data['attribute_color'] ?? '');
        $sizeValue = trim($data['attribute_size'] ?? '');
        
        if (empty($colorValue) && empty($sizeValue)) {
            return;
        }
        
        // Create Color variation if present
        if (!empty($colorValue)) {
            $colorVariation = $this->getOrCreateVariation('Color', 'color');
            $this->addVariationValueToProduct($product, $colorVariation, $colorValue);
            
            // Attach variation to product (use syncWithoutDetaching to avoid duplicates)
            $product->variations()->syncWithoutDetaching([$colorVariation->id]);
        }
        
        // Create Size variation if present
        if (!empty($sizeValue)) {
            $sizeVariation = $this->getOrCreateVariation('Size', 'text');
            $this->addVariationValueToProduct($product, $sizeVariation, $sizeValue);
            
            // Attach variation to product (use syncWithoutDetaching to avoid duplicates)
            $product->variations()->syncWithoutDetaching([$sizeVariation->id]);
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
            
            $uid = md5($product->id . '-' . $variantName);
            
            // Check if variant already exists
            $existingVariant = ProductVariant::where('product_id', $product->id)
                ->where('name', $variantName)
                ->first();
                
            if (!$existingVariant) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'uid' => $uid,
                    'uids' => Str::slug($variantName),
                    'name' => $variantName,
                    'sku' => $product->sku . '-' . Str::upper(Str::random(6)),
                    'price' => $data['price'] ?? $product->price,
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
     * Get or create a variation by name
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
        
        // Check if value already exists (by value field, case-insensitive)
        $existingValue = VariationValue::where('variation_id', $variation->id)
            ->whereRaw('LOWER(value) = ?', [strtolower($value)])
            ->first();
            
        if (!$existingValue) {
            $variationValue = new VariationValue();
            $variationValue->variation_id = $variation->id;
            $variationValue->uid = Str::random(12);
            $variationValue->position = VariationValue::where('variation_id', $variation->id)->count() + 1;
            $variationValue->value = $value;
            $variationValue->save();
            
            // Set label (translatable field)
            try {
                $variationValue->label = $value;
                $variationValue->save();
            } catch (\Exception $e) {
                Log::info("Could not set variation value label", ['value' => $value, 'error' => $e->getMessage()]);
            }
        }
    }
}

