#!/bin/sh
name=swiv
version=1.0

# files
mkdir -p $name/usr/bin
cp swiv.php $name/usr/bin/swiv
chmod +x $name/usr/bin/swiv

mkdir -p $name/etc/systemd/system
cp swiv.service $name/etc/systemd/system

# metadata
mkdir -p $name/DEBIAN
cat > $name/DEBIAN/control <<EOF
Package: $name
Description: simple web image viewer
Architecture: all
Version: $version
Maintainer: Vasileios Pasialiokis <vas@tsuku.ro>
EOF

dpkg-deb --root-owner-group -b $name
