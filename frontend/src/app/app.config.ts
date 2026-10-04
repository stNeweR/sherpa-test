import { ApplicationConfig, isDevMode, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideAnimationsAsync } from '@angular/platform-browser/animations/async';
import { provideEffects } from '@ngrx/effects';
import { provideStore } from '@ngrx/store';
import { provideStoreDevtools } from '@ngrx/store-devtools';

import { routes } from './app.routes';
import { apiInterceptor } from './core/http/api.interceptor';
import { ImportEffects } from './store/import/import.effects';
import { importFeatureKey, importReducer } from './store/import/import.reducer';
import { ProductsEffects } from './store/products/products.effects';
import { productsFeatureKey, productsReducer } from './store/products/products.reducer';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withInterceptors([apiInterceptor])),
    provideAnimationsAsync(),
    provideStore({
      [productsFeatureKey]: productsReducer,
      [importFeatureKey]: importReducer,
    }),
    provideEffects(ProductsEffects, ImportEffects),
    provideStoreDevtools({ maxAge: 25, logOnly: !isDevMode() }),
  ],
};
