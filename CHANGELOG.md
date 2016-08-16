# 0.1.4 (2016-08-16)
**Fixed bugs:**
- https cron url
- sftp recursive get files adapted to new library

**Implemented enhancements:**
- Passive mode and ssl connection for ftp synchronization
- Key file authentication for sftp synchronization

# 0.1.3 (2016-05-06)
**Fixed bugs:**
- Image will now be correctly imported when their path contains special characters
- Default price if no mapping exists in product import is now a valid value
- If any variant has been removed since the last variant import, product import will now skip it
- The tab icon for Prestaneo is now displayed

**Implemented enhancements:**
- Product import now supports cross-selling and attachments
- During product import, values for multi-select features will now be translated
- Variant import creates hidden products that will take their name and reference from the group

# 0.1.2 (2016-04-22)
**Fixed bugs:**
- Check if MOD_FTP constant hasn't already been defined for compatibility with some other modules
- Image import deactivated for unpacked import
- File upload directly launch import
- Variant import create draft hidden product if not already imported

**Implemented enhancements:**
- Feature values import added
- Configuration data prefixed by engine name
- Native distant files import (unpacked product's files)
- Manual entity delete impact association
- More flexible mapping entities
- Cross-selling update on product import

# 0.1.1 (2016-03-23)
**Fixed bugs:**
- Permanent image import based on file existence instead of archive origin

**Implemented enhancements:**
- Import all data button added
- Multiple images support for products and it's combinations

# 0.1.0 (2016-03-09)
- Initial release : **It's just you and me Prestashop!**
