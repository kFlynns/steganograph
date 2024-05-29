# KFlynns\Steganograph\Steganograph
## A library for hiding files in images


### Basic usage - encode
```
$steganograph->packFileIntoImage(
    __DIR__ . '/plain.png',     // the file path to the image, where the data will be hided in
    __DIR__ . '/secret.txt',    // the file that contains the data, that need to be hidden 
    __DIR__ . '/out.png'        // the path to the resulting image
);
```

Now you can transfer image "out.png" to the destination, or save it for future purposes.
Please note, that you can use the PNG file format only, because it's necessary to have a lossless compression.
The library does not check whether the data amount of the file, that should be encoded, fits into the picture.
Only 1 bit will be encoded into 1 pixel. So the dimension (width * height) of the target picture must be 8 times the file size at least.

### Basic usage - decode
```
$steganograph->extractFileFromImage(
    __DIR__ . '/out.png',       // the prevoiusly packed image
    __DIR__ . '/secret.txt',    // the hidden file as before
);
```