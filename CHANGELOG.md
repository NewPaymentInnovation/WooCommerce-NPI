## Version 4.1.0-rc
=================

### Added
- **Payment Methods**: Card (hosted fields), Hosted Payment Page, Google Pay, and Apple Pay
- **Subscriptions**: WooCommerce Subscriptions support for recurring payments via card tokenisation
- **Express Payment Options**: Google Pay and Apple Pay express checkout buttons on product and cart pages

## Version 4.1.1-rc
=================

### Updated
- **Apple Pay Validation**: Method of domain name retrieval updated.

## Version 4.1.2-rc
=================

### Fixed
- **Apple Pay PostCode**: Corrected Apple Pay postcode extraction to account for 4 characters at the start of the postcode.

## Version 4.1.3
=================

### Fixed
- **Apple Pay PostCode**: Added helper to handle shipping options that filter by postcode when partial postcode provided.
- **Google Pay billing phone**: Fixed billing phone not required when checkout form does require it.
