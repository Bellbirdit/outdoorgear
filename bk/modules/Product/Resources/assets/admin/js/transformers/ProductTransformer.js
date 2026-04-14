import _ from "lodash";
import { useVariants } from "../composables/useVariants";

const { variantPosition, hasAnyVariant } = useVariants();

export default class {
    constructor() {
        this.data = {};
    }

    transformMedia() {
        this.data.media = this.data.media.map((data) => data.id);
    }

    transformAttributes() {
        this.data.attributes = this.data.attributes
            .filter(({ attribute_id }) => attribute_id !== "")
            .reduce((accumulator, { attribute_id, uid, values }) => {
                return { ...accumulator, [uid]: { attribute_id, values } };
            }, {});
    }

    transformDownloads() {
        this.data.downloads = this.data.downloads
            .filter(({ id }) => id !== null)
            .map(({ id }) => id);
    }

    transformVariations() {
        const PATHS = {
            text: ["id", "uid", "label"],
            color: ["id", "uid", "label", "color"],
            image: ["id", "uid", "label", "image"],
        };

        this.data.variations = this.data.variations
            .filter(({ name, type }) => Boolean(name) || type !== "")
            .reduce((accumulator, variation) => {
                if (variation.type === "") {
                    variation.values = [];
                } else {
                    variation.values = variation.values.reduce(
                        (valueAccumulator, value) => {
                            value = _.pick(value, PATHS[variation.type]);

                            if (variation.type === "image" && value.image?.id) {
                                value.image = value.image.id;
                            }

                            return { ...valueAccumulator, [value.uid]: value };
                        },
                        {},
                    );
                }

                return { ...accumulator, [variation.uid]: variation };
            }, {});
    }

    transformVariants() {
        variantPosition.value = 0;

        this.data.variants = this.data.variants.reduce(
            (accumulator, variant) => {
                variant.position = variantPosition.value++;
                variant.media = variant.media.map(({ id }) => id);

                return { ...accumulator, [variant.uid]: variant };
            },
            {},
        );
    }

    getOptionType(option) {
        const TYPES = {
            text: ["field", "textarea"],
            select: [
                "dropdown",
                "checkbox",
                "checkbox_custom",
                "radio",
                "radio_custom",
                "multiple_select",
            ],
            date: ["date", "date_time", "time"],
        };

        for (const [type, values] of Object.entries(TYPES)) {
            if (values.includes(option.type)) {
                return type;
            }
        }
    }

    transformOptions() {
        const PATHS = {
            text: ["id", "uid", "price", "price_type"],
            select: ["id", "uid", "label", "price", "price_type"],
            date: ['id', 'uid', 'price', 'price_type']
        };

        this.data.options = this.data.options
            .filter(({ name, type }) => Boolean(name) || type !== "")
            .reduce((accumulator, option) => {
                if (option.type === "") {
                    option.values = [];
                } else {
                    option.values = option.values.reduce(
                        (valueAccumulator, value) => {
                            value = _.pick(value, PATHS[this.getOptionType(option)]);
                            
                            return { ...valueAccumulator, [value.uid]: value };
                        },
                        {},
                    );
                }

                return { ...accumulator, [option.uid]: option };
            }, {});
    }

    transform(data) {
        this.data = JSON.parse(JSON.stringify(data));

        if (hasAnyVariant.value) {
            this.data = {
                ...this.data,
                price: null,
                special_price: null,
                special_price_type: "fixed",
                special_price_start: null,
                special_price_end: null,
                sku: null,
                manage_stock: 0,
                qty: null,
                in_stock: 1,
            };
        }

        this.transformMedia();
        this.transformAttributes();
        this.transformDownloads();
        this.transformVariations();
        this.transformVariants();
        this.transformOptions();

        return this.data;
    }
}
