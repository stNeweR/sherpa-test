import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * Инлайновые SVG-иконки вместо лигатур Material Icons:
 * не требует подключения шрифта и работает офлайн.
 */
const ICONS = {
  'arrow-left': ['M19 12H5', 'M11 6l-6 6 6 6'],
  alert: ['M12 3.5 22 20H2z', 'M12 10v4.5', 'M12 17.4h.01'],
  'image-off': ['M3 5h18v14H3z', 'M4 20 20 4'],
  box: ['M3 7l9-4 9 4v10l-9 4-9-4z', 'M3 7l9 4 9-4', 'M12 11v10'],
  upload: ['M12 16V4', 'M8 8l4-4 4 4', 'M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2'],
  file: ['M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z', 'M14 2v5h5'],
} as const;

export type IconName = keyof typeof ICONS;

@Component({
  selector: 'app-icon',
  template: `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      @for (d of paths(); track d) {
        <path [attr.d]="d" />
      }
    </svg>
  `,
  styles: `
    :host {
      display: inline-flex;
      width: 1.25rem;
      height: 1.25rem;
      flex: none;
      vertical-align: middle;
    }

    svg {
      width: 100%;
      height: 100%;
      fill: none;
      stroke: currentColor;
      stroke-width: 1.75;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppIcon {
  readonly name = input.required<IconName>();

  protected readonly paths = computed<readonly string[]>(() => ICONS[this.name()]);
}
