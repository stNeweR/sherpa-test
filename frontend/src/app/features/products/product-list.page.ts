import { ChangeDetectionStrategy, Component, OnInit, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatToolbarModule } from '@angular/material/toolbar';
import { RouterLink } from '@angular/router';
import { Store } from '@ngrx/store';

import { imageSrc } from '../../core/models/product.model';
import { AppIcon } from '../../ui/icon';
import { ProductsActions } from '../../store/products/products.actions';
import {
  ProductsState,
  selectError,
  selectItems,
  selectLoading,
  selectPages,
  selectQuery,
  selectTotal,
} from '../../store/products/products.reducer';

@Component({
  selector: 'app-product-list-page',
  imports: [
    RouterLink,
    AppIcon,
    MatButtonModule,
    MatCardModule,
    MatChipsModule,
    MatFormFieldModule,
    MatInputModule,
    MatPaginatorModule,
    MatProgressBarModule,
    MatProgressSpinnerModule,
    MatToolbarModule,
  ],
  templateUrl: './product-list.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductListPage implements OnInit {
  private readonly store = inject(Store<ProductsState>);

  protected readonly items = toSignal(this.store.select(selectItems), { initialValue: [] });
  protected readonly total = toSignal(this.store.select(selectTotal), { initialValue: 0 });
  protected readonly pages = toSignal(this.store.select(selectPages), { initialValue: 0 });
  protected readonly loading = toSignal(this.store.select(selectLoading), { initialValue: false });
  protected readonly error = toSignal(this.store.select(selectError), { initialValue: null });
  protected readonly query = toSignal(this.store.select(selectQuery), {
    initialValue: { page: 1, limit: 12, search: '', priceFrom: null, priceTo: null },
  });

  protected readonly empty = computed(() => !this.loading() && this.items().length === 0);

  ngOnInit(): void {
    this.refresh();
  }

  protected refresh(): void {
    this.store.dispatch(ProductsActions.loadPage({ query: this.query() }));
  }

  protected retry(): void {
    this.store.dispatch(ProductsActions.loadFailure({ error: '' }));
    this.refresh();
  }

  protected onSearch(value: string): void {
    this.store.dispatch(ProductsActions.searchChanged({ search: value }));
  }

  protected onPriceFrom(value: string): void {
    this.store.dispatch(ProductsActions.priceFromChanged({ priceFrom: parseAmount(value) }));
  }

  protected onPriceTo(value: string): void {
    this.store.dispatch(ProductsActions.priceToChanged({ priceTo: parseAmount(value) }));
  }

  protected onResetFilters(): void {
    this.store.dispatch(ProductsActions.filtersReset());
  }

  protected onPage(event: PageEvent): void {
    if (event.pageSize !== this.query().limit) {
      this.store.dispatch(ProductsActions.limitChanged({ limit: event.pageSize }));

      return;
    }

    this.store.dispatch(ProductsActions.pageChanged({ page: event.pageIndex + 1 }));
  }

  protected imageUrl(thumbnail: string | null): string | null {
    return imageSrc(thumbnail === null ? null : { url: thumbnail, path: null });
  }

  protected trackById(_: number, item: { id: number }): number {
    return item.id;
  }
}

function parseAmount(value: string): number | null {
  const normalized = value.trim().replace(',', '.');
  const parsed = Number.parseFloat(normalized);

  return Number.isFinite(parsed) && normalized !== '' ? parsed : null;
}
