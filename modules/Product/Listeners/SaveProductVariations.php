<?php

namespace Modules\Product\Listeners;

use Modules\Product\Entities\Product;

class SaveProductVariations
{
    /**
     * Handle the event.
     *
     * @param Product $product
     *
     * @return void
     */
    public function handle(Product $product): void
    {
        $ids = $this->getDeleteCandidates($product);

        if ($ids->isNotEmpty()) {
            $product->variations()->detach($ids);
        }

        $this->saveVariations($product);
    }


    private function getDeleteCandidates($product)
    {
        return $product
            ->variations()
            ->pluck('id')
            ->diff(array_pluck($this->variations(), 'id'));
    }


    private function variations()
    {
        return array_filter(request('variations', []), function ($variation) {
            return !is_null($variation['name']);
        });
    }


    private function saveVariations($product): void
    {
        $counter = 0;

        foreach (array_reset_index($this->variations()) as $attributes) {
            $attributes['position'] = ++$counter;
            
            // If it's a global variation, find existing by UID instead of creating new
            if ($attributes['is_global'] === true) {
                // Try to find existing variation by UID
                $existingVariation = \Modules\Variation\Entities\Variation::where('uid', $attributes['uid'] ?? '')->first();
                
                if ($existingVariation) {
                    // Attach existing global variation to product if not already attached
                    if (!$product->variations->contains($existingVariation->id)) {
                        $product->variations()->attach($existingVariation->id);
                    }
                    
                    // Save values for existing variation
                    $existingVariation->saveValues($attributes['values'] ?? []);
                    continue;
                }
            }

            $attributes['is_global'] = false;

            $variation = $product->variations()->updateOrCreate(['id' => $attributes['id'] ?? null], $attributes);

            $variation->saveValues($attributes['values'] ?? []);
        }
    }
}
