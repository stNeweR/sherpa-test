import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { Page, ProductCard, ProductListQuery, ProductSummary } from '../models/product.model';

@Injectable({ providedIn: 'root' })
export class ProductsApiService {
  private readonly http = inject(HttpClient);

  list(query: ProductListQuery): Observable<Page<ProductSummary>> {
    let params = new HttpParams().set('page', query.page).set('limit', query.limit);

    if (query.search.trim() !== '') {
      params = params.set('name', query.search.trim());
    }

    if (query.priceFrom !== null) {
      params = params.set('price_from', query.priceFrom);
    }

    if (query.priceTo !== null) {
      params = params.set('price_to', query.priceTo);
    }

    return this.http.get<Page<ProductSummary>>('/api/products', { params });
  }

  card(externalCode: string): Observable<ProductCard> {
    return this.http.get<ProductCard>(`/api/products/${encodeURIComponent(externalCode)}`);
  }
}
