export interface ProductAttribute {
  key: string;
  value: string;
}

export interface ProductImage {
  url: string;
  path: string | null;
}

export interface ProductSummary {
  id: number;
  external_code: string;
  name: string;
  price: string;
  discount: string;
  created_at: string;
  thumbnail_url: string | null;
}

export interface ProductCard {
  id: number;
  external_code: string;
  name: string;
  description: string;
  price: string;
  purchase_price: string;
  discount: string;
  created_at: string;
  updated_at: string;
  attributes: ProductAttribute[];
  images: ProductImage[];
}

export interface Pagination {
  page: number;
  limit: number;
  total: number;
  pages: number;
}

export interface Page<TItem> {
  items: TItem[];
  pagination: Pagination;
}

export interface ProductListQuery {
  page: number;
  limit: number;
  search: string;
  priceFrom: number | null;
  priceTo: number | null;
}

export const DEFAULT_PRODUCT_LIST_QUERY: ProductListQuery = {
  page: 1,
  limit: 12,
  search: '',
  priceFrom: null,
  priceTo: null,
};

/** Путь картинки относительно /media, иначе исходный URL. */
export function imageSrc(image: ProductImage | null | undefined): string | null {
  if (!image) {
    return null;
  }

  return image.path ?? image.url;
}
