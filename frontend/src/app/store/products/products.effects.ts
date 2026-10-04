import { inject } from '@angular/core';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { Store } from '@ngrx/store';
import { catchError, debounceTime, map, of, switchMap, withLatestFrom } from 'rxjs';

import { describeError } from '../../core/http/error-message';
import { ProductsApiService } from '../../core/services/products-api.service';
import { ProductsActions } from './products.actions';
import { ProductsState, selectQuery } from './products.reducer';

export class ProductsEffects {
  private readonly actions$ = inject(Actions);
  private readonly store = inject(Store<ProductsState>);
  private readonly api = inject(ProductsApiService);

  readonly loadOnQueryChange$ = createEffect(() =>
    this.actions$.pipe(
      ofType(
        ProductsActions.searchChanged,
        ProductsActions.priceFromChanged,
        ProductsActions.priceToChanged,
        ProductsActions.pageChanged,
        ProductsActions.limitChanged,
        ProductsActions.filtersReset,
      ),
      debounceTime(250),
      withLatestFrom(this.store.select(selectQuery)),
      map(([, query]) => ProductsActions.loadPage({ query })),
    ),
  );

  readonly loadOnEnter$ = createEffect(() =>
    this.actions$.pipe(
      ofType(ProductsActions.loadPage),
      switchMap(({ query }) =>
        this.api.list(query).pipe(
          map((page) => ProductsActions.loadSuccess({ page })),
          catchError((error: unknown) =>
            of(ProductsActions.loadFailure({ error: describeError(error) })),
          ),
        ),
      ),
    ),
  );
}
