import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatDividerModule } from '@angular/material/divider';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatToolbarModule } from '@angular/material/toolbar';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Observable, catchError, distinctUntilChanged, map, of, startWith, switchMap } from 'rxjs';

import { describeError } from '../../core/http/error-message';
import { ProductCard, ProductImage, imageSrc } from '../../core/models/product.model';
import { ProductsApiService } from '../../core/services/products-api.service';
import { AppIcon } from '../../ui/icon';

type CardState =
  | { status: 'loading' }
  | { status: 'error'; error: string }
  | { status: 'ready'; product: ProductCard };

type LoadedCardState = Extract<CardState, { status: 'ready' }>;

@Component({
  selector: 'app-product-card-page',
  imports: [
    DatePipe,
    RouterLink,
    AppIcon,
    MatButtonModule,
    MatCardModule,
    MatChipsModule,
    MatDividerModule,
    MatProgressBarModule,
    MatToolbarModule,
  ],
  templateUrl: './product-card.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductCardPage {
  private readonly api = inject(ProductsApiService);
  private readonly route = inject(ActivatedRoute);

  private readonly externalCode$ = this.route.paramMap.pipe(
    map((params) => params.get('externalCode') ?? ''),
    distinctUntilChanged(),
  );

  private readonly state$: Observable<CardState> = this.externalCode$.pipe(
    switchMap((externalCode): Observable<CardState> => {
      if (externalCode === '') {
        return of<CardState>({ status: 'error', error: 'Не указан артикул' });
      }

      return this.api.card(externalCode).pipe(
        map((product): LoadedCardState => ({ status: 'ready', product })),
        catchError((error: unknown) =>
          of<CardState>({
            status: 'error',
            error: isNotFound(error) ? 'Товар не найден' : describeError(error),
          }),
        ),
        startWith<CardState>({ status: 'loading' }),
      );
    }),
  );

  protected readonly state = toSignal(this.state$, { initialValue: null });

  protected readonly title = computed(() => {
    const state = this.state();

    return state !== null && state.status === 'ready' ? state.product.name : 'Карточка товара';
  });

  protected imageSrc(image: ProductImage): string {
    return imageSrc(image) ?? '';
  }
}

function isNotFound(error: unknown): boolean {
  return (
    typeof error === 'object' && error !== null && (error as { status?: number }).status === 404
  );
}
