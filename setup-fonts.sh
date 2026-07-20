#!/bin/bash
# Download the bundled Inter font for GD backend rendering.
# Run once: bash setup-fonts.sh
mkdir -p public/assets/fonts
curl -sL -o public/assets/fonts/Inter.ttf \
  "https://github.com/rsms/inter/raw/master/fonts/inter/Inter-Regular.ttf"
echo "Inter.ttf: $(wc -c < public/assets/fonts/Inter.ttf) bytes"
