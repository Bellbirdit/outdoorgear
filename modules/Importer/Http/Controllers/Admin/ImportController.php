<?php

namespace Modules\Importer\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Importer\Imports\ProductsImport;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Maatwebsite\Excel\Validators\ValidationException;

use Maatwebsite\Excel\Facades\Excel as ExportExcel;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Modules\Product\Entities\Product;

class ImportController extends Controller
{
    public function index()
    {
        $exceptions = [];

        if (session("exceptions")) {
            $exceptions = session("exceptions");
            
            session()->forget("exceptions");
        }

        return view("importer::import.index", compact("exceptions"));
    }

    public function store(Request $request)
    {
        session()->forget("exceptions");
        @set_time_limit(0);

        $request->validate([
            "products" => "required|mimes:xlsx,xls,csv|max:9999",
            "images" => "nullable|mimes:zip",
        ]);

        $app_path = app_path() . "\\";
        $app_path = str_replace("\\", "/", $app_path);

        try {
            ExcelFacade::import(
                new ProductsImport(),
                $request->file("products")
            );

            return back()->with(
                "success",
                trans("importer::importer.products_imported_successfully")
            );
        } catch (ValidationException $e) {
            $failures = $e->failures();

            return back()
                ->withErrors($failures)
                ->withInput();
        } catch (\Exception $e) {
            return back()->with(
                "error",
                sprintf(
                    "%s. %s.",
                    trans("importer::importer.something_went_wrong"),
                    $e->getMessage()
                )
            );
        }
    }
    
    public function export(Request $request)
    {
        $products = Product::select('product_translations.name', 'products.sku', 'products.price')->leftJoin('product_translations', 'product_translations.product_id', '=', 'products.id')->get();
    
        return ExportExcel::download(
            new class($products) implements FromCollection, WithHeadings {
                protected $products;
    
                public function __construct($products)
                {
                    $this->products = $products;
                }
    
                public function collection()
                {
                    // Convert Product models into array rows
                    return $this->products->map(function($product) {
                        return [
                            $product->name,
                            $product->sku,
                            $product->price,
                        ];
                    });
                }
    
                public function headings(): array
                {
                    return ['Name', 'SKU', 'Price'];
                }
            },
            'products.xlsx'
        );
    }
    
    public function export123(Request $request)
    {
        // $products = Product::all();
        // $products = Product::select('id', 'name', 'price', 'sku')->take(5)->get();
        // $products = Product::take(5)->get();
        $products = Product::select('slug', 'price', 'sku')->get();
        // echo "<pre>"; print_r($products); die;
    
        return ExportExcel::download(
            new class($products) implements FromCollection, WithHeadings {
                protected $products;
    
                public function __construct($products)
                {
                    $this->products = $products;
                }
    
                public function collection()
                {
                    return $this->products;
                }
    
                public function headings(): array
                {
                    return ['Slug', 'Price', 'SKU'];
                }
            },
            'products.xlsx'
        );
    }
}
