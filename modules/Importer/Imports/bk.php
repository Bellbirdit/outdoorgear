<?php

namespace Modules\Importer\Imports;

use Exception;
use finfo;
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

    public function onRow(Row $row): void
    {
        $row_data = $row->toArray();
       
  
        $validator = Validator::make($row_data, [
 
            "name" => "required|string|max:255",
            "description" => "nullable|string", 
             "product_code" => "nullable|string|max:255", 
             "type"  => "nullable|string|max:255", 
            "short_description" => "nullable|string",
            "key_features" => "nullable|string",
            "why_choose" => "nullable|string",
            "tips_guide" => "nullable|string",
            "meta_keywords" => "nullable|string",
            " Keywords" => "nullable|string",
            "barcode" => "nullable|string|max:255",
            "technical_specs" => "nullable|string",
            "supplier_name" => "nullable|string|max:255",
            "supplier_code" => "nullable|string|max:255",

            "active" => "nullable|integer|in:1,0",
            "brand" => "nullable|string",
           "categories" => "required|string",
            "tags" => "nullable|string",
            "tax_class_id" => ["nullable", Rule::exists("tax_classes", "id")],
            "price" =>
            "required_without:variants|nullable|numeric|min:0|max:99999999999999",
            "special_price" => "nullable|numeric|min:0|max:99999999999999",
            "special_price_type" => [
                "nullable",
                Rule::in(["fixed", "percent"]),
            ],
            "special_price_start" => "nullable|date|before:special_price_end",
            "special_price_end" => "nullable|date|after:special_price_start",
            "manage_stock" => "nullable|boolean",
            "quantity" => "required_if:manage_stock,1|nullable|numeric",
            "in_stock" => "nullable|boolean",
            "new_from" => "nullable|date",
            "new_to" => "nullable|date",
            "additional_images" => "nullable|string",
            "base_image" => "nullable|string",
            "meta_title" => "nullable|string",
            "meta_description" => "nullable|string",
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

            $data["options"] = [];
            $data['brand_id'] = $data['brand'];

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
            $test_arr =  array_filter(
            [
                "name" => $data["name"],
                "sku" => $data["sku"],
                "description" => $data["description"] ?? null,
                "short_description" => $data["short_description"] ?? null,
                 "type" => $data["type"] ?? "simple",
                 "product_code" => $data["product_code"] ?? null, 
                "key_features" => $data["key_features"] ?? null,
                "why_choose" => $data["why_choose"] ?? null,
                "tips_guide" => $data["tips_guide"] ?? null,
                "keywords" => $data[" Keywords"] ?? null,
                "barcode" => $data["barcode"] ?? "",
                "technical_specs" => $data["technical_specs"] ?? "",
                "supplier_name" => $data["supplier_name"] ?? "",
                "supplier_code" => $data["supplier_code"] ?? "",
                "is_active" => $data["active"] ?? 1,
                "brand" => empty($data["brand"])
                    ? null
                    : $this->getOrCreateBrandByName($data["brand"])->id,
                "categories" => $this->mapExploded(
                    $data["categories"],
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
                "Attribute:Color" => $data["Attribute:Color"] ?? null,
                "Attribute:Size"=>$data["Attribute:Size"] ?? null,
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
        // ds($data)->label('normalizeFiles');
        return [
            "base_image" => !empty($data["base_image"])
                ? $this->explode($data["base_image"])
                : null,
            "additional_images" => !empty($data["additional_images"])
                ? $this->explode($data["additional_images"])
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
}

