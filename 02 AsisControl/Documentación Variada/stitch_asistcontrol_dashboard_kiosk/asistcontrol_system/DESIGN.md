---
name: AsistControl System
colors:
  surface: '#faf9fc'
  surface-dim: '#dad9dd'
  surface-bright: '#faf9fc'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f4f3f7'
  surface-container: '#eeedf1'
  surface-container-high: '#e9e7eb'
  surface-container-highest: '#e3e2e6'
  on-surface: '#1a1c1e'
  on-surface-variant: '#43474e'
  inverse-surface: '#2f3033'
  inverse-on-surface: '#f1f0f4'
  outline: '#74777f'
  outline-variant: '#c4c6cf'
  surface-tint: '#455f87'
  primary: '#022448'
  on-primary: '#ffffff'
  primary-container: '#1e3a5f'
  on-primary-container: '#8aa4cf'
  inverse-primary: '#adc8f5'
  secondary: '#226296'
  on-secondary: '#ffffff'
  secondary-container: '#8bc3fd'
  on-secondary-container: '#005083'
  tertiary: '#341f00'
  on-tertiary: '#ffffff'
  tertiary-container: '#503300'
  on-tertiary-container: '#c69b5f'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d5e3ff'
  primary-fixed-dim: '#adc8f5'
  on-primary-fixed: '#001c3b'
  on-primary-fixed-variant: '#2d486d'
  secondary-fixed: '#d0e4ff'
  secondary-fixed-dim: '#9bcbff'
  on-secondary-fixed: '#001d34'
  on-secondary-fixed-variant: '#004a79'
  tertiary-fixed: '#ffddb2'
  tertiary-fixed-dim: '#edbf7f'
  on-tertiary-fixed: '#291800'
  on-tertiary-fixed-variant: '#60410c'
  background: '#faf9fc'
  on-background: '#1a1c1e'
  surface-variant: '#e3e2e6'
typography:
  h1:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  h2:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.01em
  h3:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
    letterSpacing: '0'
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
    letterSpacing: '0'
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
    letterSpacing: '0'
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
    letterSpacing: '0'
  label-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
    letterSpacing: 0.02em
  data-tabular:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  gutter: 24px
  container-margin: 40px
---

## Brand & Style

The design system is built for "AsistControl," a professional employee attendance SaaS that prioritizes reliability, precision, and ease of use. The brand personality is institutional yet accessible—evoking the stability of a financial tool with the modern fluidity of a productivity app. 

The chosen style is **Corporate / Modern**, leaning heavily into a "Soft Professionalism" aesthetic. It utilizes a layered approach to information architecture, using subtle tonal shifts to separate navigation from content. The interface avoids aggressive visual cues, instead using whitespace and a refined blue-centric palette to reduce cognitive load for HR administrators managing complex data.

## Colors

This design system utilizes a monochromatic blue foundation to establish trust and hierarchy. 
- **Primary & Mid Blue:** Reserved for high-level navigation, primary actions, and brand identification.
- **Accent Blue:** Used for interactive states and focus indicators.
- **Surface Strategy:** The background uses a very cool, bright tint (#F8FBFF), while cards utilize a slightly more saturated blue-white (#EBF4FF) to create "soft-container" depth without relying on heavy shadows.
- **Feedback:** Success, Warning, and Error colors follow industry standards but are calibrated for high legibility against the light-blue backgrounds.

## Typography

The design system uses **Inter** exclusively to ensure maximum legibility across dense data tables and administrative dashboards. 
- **Hierarchy:** Headlines use tighter letter-spacing and heavier weights to stand out against UI chrome.
- **Readability:** Body text uses a standard 1.5x line-height ratio. 
- **Data Display:** For attendance logs and time tracking, use `font-feature-settings: "tnum"` (tabular figures) to ensure columns of numbers align vertically for quick scanning.

## Layout & Spacing

This design system employs a **12-column fluid grid** for the main content area, paired with a fixed left-hand navigation sidebar (260px). 

- **Grid:** 24px gutters provide ample breathing room between data widgets.
- **Rhythm:** An 8px base grid governs all padding and margin decisions. 
- **Density:** Dashboard views should utilize `md` (16px) spacing, while dense data tables and side panels may drop to `sm` (8px) internal padding to increase information density without sacrificing clarity.

## Elevation & Depth

Hierarchy is established through **Tonal Layers** supplemented by **Ambient Shadows**. 

1. **Base Layer:** The application background (#F8FBFF).
2. **Mid Layer:** Cards and secondary panels using the Card Fill (#EBF4FF). These do not require shadows but use the Border color (#D6E4F0) for definition.
3. **Top Layer:** Primary action cards and White (#FFFFFF) surfaces. These use a very soft, diffused shadow: `0 4px 12px rgba(30, 58, 95, 0.05)`.
4. **Overlay Layer:** Modals and dropdowns. These use a more pronounced shadow: `0 12px 32px rgba(30, 58, 95, 0.12)` and a 1px border.

The use of a navy-tinted shadow ensures that depth feels integrated with the blue color palette rather than appearing as a grey smudge.

## Shapes

The design system adheres to a consistent **12px (0.75rem)** radius for all primary containers and buttons.

- **Small elements:** Checkboxes and small tags use a 4px radius.
- **Standard components:** Buttons, input fields, and small cards use the 12px radius.
- **Large containers:** Main dashboard widgets and modals use a 16px (rounded-lg) radius.
- **Iconography:** Use "Outline" style icons from Heroicons with a 1.5px or 2px stroke width to match the weight of the Inter typeface.

## Components

- **Buttons:** 
  - *Primary:* Deep Navy (#1E3A5F) with White text. 12px radius.
  - *Secondary:* Card Fill (#EBF4FF) with Mid Blue (#2D6A9F) text. Outline-less.
- **Input Fields:** White background, 1px Border (#D6E4F0), 12px radius. Active state uses Accent Blue (#4A90D9) border and a 2px outer glow.
- **Chips/Badges:** Use "Pill" shapes for status. Success uses #27AE60 at 10% opacity for the background and 100% for the text.
- **Attendance Cards:** Large white surfaces with 12px radius and the subtle ambient shadow. Use a 4px left-border accent in Primary Mid to denote active selection.
- **Data Tables:** Row-based layout with no vertical borders. Use #D6E4F0 for horizontal dividers. Hover state for rows uses #F8FBFF.
- **Attendance Toggle:** A specialized "Clock-in/out" component using a large, high-contrast Primary button with a clear icon and bold label.