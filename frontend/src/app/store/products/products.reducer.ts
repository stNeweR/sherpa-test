import { createFeature, createReducer, on } from '@ngrx/store';

import {
  DEFAULT_PRODUCT_LIST_QUERY,
  ProductListQuery,
  ProductSummary,
} from '../../core/models/product.model';
import { ProductsActions } from './products.actions';

export interface ProductsState {
  query: ProductListQuery;
  items: ProductSummary[];
  total: number;
  pages: number;
  loading: boolean;
  error: string | null;
}

export const initialProductsState: ProductsState = {
  query: DEFAULT_PRODUCT_LIST_QUERY,
  items: [],
  total: 0,
  pages: 0,
  loading: false,
  error: null,
};

const withQuery = (state: ProductsState, patch: Partial<ProductListQuery>): ProductsState => ({
  ...state,
  query: { ...state.query, ...patch },
});

export const productsFeature = createFeature({
  name: 'products',
  reducer: createReducer(
    initialProductsState,
    on(ProductsActions.loadPage, (state, { query }) =>
      withQuery({ ...state, loading: true, error: null }, query),
    ),
    on(ProductsActions.loadSuccess, (state, { page }) => ({
      ...state,
      items: page.items,
      total: page.pagination.total,
      pages: page.pagination.pages,
      loading: false,
      error: null,
    })),
    on(ProductsActions.loadFailure, (state, { error }) => ({ ...state, loading: false, error })),
    on(ProductsActions.searchChanged, (state, { search }) => withQuery(state, { search, page: 1 })),
    on(ProductsActions.priceFromChanged, (state, { priceFrom }) =>
      withQuery(state, { priceFrom, page: 1 }),
    ),
    on(ProductsActions.priceToChanged, (state, { priceTo }) =>
      withQuery(state, { priceTo, page: 1 }),
    ),
    on(ProductsActions.pageChanged, (state, { page }) => withQuery(state, { page })),
    on(ProductsActions.limitChanged, (state, { limit }) => withQuery(state, { limit, page: 1 })),
    on(ProductsActions.filtersReset, (state) =>
      withQuery(state, { ...DEFAULT_PRODUCT_LIST_QUERY }),
    ),
  ),
});

export const {
  name: productsFeatureKey,
  reducer: productsReducer,
  selectQuery,
  selectItems,
  selectTotal,
  selectPages,
  selectLoading,
  selectError,
} = productsFeature;
