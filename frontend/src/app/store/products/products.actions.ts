import { createActionGroup, emptyProps, props } from '@ngrx/store';

import { Page, ProductListQuery, ProductSummary } from '../../core/models/product.model';

export const ProductsActions = createActionGroup({
  source: 'Products',
  events: {
    'Load page': props<{ query: ProductListQuery }>(),
    'Load success': props<{ page: Page<ProductSummary> }>(),
    'Load failure': props<{ error: string }>(),
    'Search changed': props<{ search: string }>(),
    'Price from changed': props<{ priceFrom: number | null }>(),
    'Price to changed': props<{ priceTo: number | null }>(),
    'Page changed': props<{ page: number }>(),
    'Limit changed': props<{ limit: number }>(),
    'Filters reset': emptyProps(),
  },
});
