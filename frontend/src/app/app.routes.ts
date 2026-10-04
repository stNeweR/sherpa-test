import { Routes } from '@angular/router';

import { authGuard } from './core/auth/auth.guard';

/** Все страницы подгружаются лениво: отдельный чанк на маршрут. */
export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'products' },
  {
    path: 'login',
    title: 'Вход — Шерпа',
    loadComponent: () => import('./features/login/login.page').then((m) => m.LoginPage),
  },
  {
    path: 'products',
    title: 'Товары — Шерпа',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/products/product-list.page').then((m) => m.ProductListPage),
  },
  {
    path: 'products/:externalCode',
    title: 'Карточка товара — Шерпа',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/products/product-card.page').then((m) => m.ProductCardPage),
  },
  {
    path: 'import',
    title: 'Импорт товаров — Шерпа',
    canActivate: [authGuard],
    loadComponent: () => import('./features/import/import.page').then((m) => m.ImportPage),
  },
  { path: '**', redirectTo: 'products' },
];
