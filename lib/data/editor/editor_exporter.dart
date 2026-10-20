import 'dart:async';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:image/image.dart' as im;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:saber/components/canvas/_circle_stroke.dart';
import 'package:saber/components/canvas/_rectangle_stroke.dart';
import 'package:saber/components/canvas/_stroke.dart';
import 'package:saber/components/canvas/canvas_preview.dart';
import 'package:saber/components/canvas/image/editor_image.dart';
import 'package:saber/components/canvas/inner_canvas.dart';
import 'package:saber/data/editor/editor_core_info.dart';
import 'package:saber/data/is_this_a_test.dart';
import 'package:screenshot/screenshot.dart';

abstract class EditorExporter {
  /// Most* strokes can be drawn to the PDF canvas as vector graphics.
  /// This function returns true if [stroke] is one of those strokes.
  ///
  /// Strokes that can't be drawn as vector graphics include:
  /// - Highlighter strokes, because PDFs don't support transparency
  /// - Pencil strokes, which need a special shader to look correct
  static bool shouldRasterizeStroke(Stroke stroke) {
    return stroke.toolId == .highlighter || stroke.toolId == .pencil;
  }

  static Future<pw.Document> generatePdf(
    EditorCoreInfo coreInfo, [
    BuildContext? context,
  ]) async {
    if (coreInfo.pages.length > 1 && coreInfo.pages.last.isEmpty) {
      // don't export the empty last page
      coreInfo = coreInfo.copyWith(
        pages: coreInfo.pages.toList()..removeLast(),
      );
    }

    final pdf = pw.Document();

    for (var pageIndex = 0; pageIndex < coreInfo.pages.length; pageIndex++) {
      final uiImage = await screenshotPage(
        coreInfo: coreInfo,
        pageIndex: pageIndex,
        requireAllImages: true,
      );
      final pw.MemoryImage background;
      try {
        final data = await uiImage.toByteData(
          format: ui.ImageByteFormat.rawRgba,
        );
        if (data == null) throw StateError('Could not render PDF page');
        final bitmap = im.Image.fromBytes(
          width: uiImage.width,
          height: uiImage.height,
          bytes: data.buffer,
          order: im.ChannelOrder.rgba,
        );
        // Keep compressed pages instead of retaining every full RGBA bitmap.
        background = pw.MemoryImage(im.encodePng(bitmap));
      } finally {
        uiImage.dispose();
      }
      final page = coreInfo.pages[pageIndex];
      final pageSize = page.size;
      pdf.addPage(
        pw.Page(
          pageFormat: PdfPageFormat(pageSize.width, pageSize.height),
          build: (pw.Context context) {
            return pw.SizedBox(
              width: pageSize.width,
              height: pageSize.height,
              child: pw.CustomPaint(
                foregroundPainter: (PdfGraphics pdfGraphics, PdfPoint size) {
                  final backgroundColor = PdfColor.fromInt(
                    coreInfo.backgroundColor?.toARGB32() ??
                        InnerCanvas.defaultBackgroundColor.toARGB32(),
                  ).flatten();

                  final strokes = page.strokes.where(
                    (stroke) => !shouldRasterizeStroke(stroke),
                  );
                  for (final stroke in strokes) {
                    final strokeColor = PdfColor.fromInt(
                      stroke.color.toARGB32(),
                    ).flatten(background: backgroundColor);

                    /// Whether we need to fill the shape, or draw its stroke
                    final bool shouldFillShape;
                    if (stroke is CircleStroke) {
                      shouldFillShape = false;
                      pdfGraphics.drawEllipse(
                        stroke.center.dx,
                        pageSize.height - stroke.center.dy,
                        stroke.radius,
                        stroke.radius,
                        clockwise: false,
                      );
                    } else if (stroke is RectangleStroke) {
                      shouldFillShape = false;
                      final strokeSize = stroke.options.size;
                      pdfGraphics.drawRRect(
                        stroke.rect.left,
                        pageSize.height - stroke.rect.bottom,
                        stroke.rect.width,
                        stroke.rect.height,
                        strokeSize / 4,
                        strokeSize / 4,
                      );
                    } else {
                      shouldFillShape = true;
                      pdfGraphics.drawShape(stroke.toSvgPath());
                    }

                    if (shouldFillShape) {
                      // fill
                      pdfGraphics.setFillColor(strokeColor);
                      pdfGraphics.fillPath();
                    } else {
                      // stroke
                      pdfGraphics.setStrokeColor(strokeColor);
                      pdfGraphics.setLineWidth(stroke.options.size);
                      pdfGraphics.strokePath();
                    }
                  }
                },
                child: pw.Image(
                  background,
                  width: pageSize.width,
                  height: pageSize.height,
                ),
              ),
            );
          },
        ),
      );
    }

    return pdf;
  }

  static Future<VoidCallback> _holdDecodedImage(ImageProvider provider) async {
    final stream = provider.resolve(ImageConfiguration.empty);
    final ready = Completer<void>();
    late ImageStreamListener listener;
    listener = ImageStreamListener(
      (info, synchronous) {
        info.dispose();
        if (!ready.isCompleted) ready.complete();
      },
      onError: (Object error, StackTrace? stack) {
        if (!ready.isCompleted) ready.completeError(error, stack);
      },
    );
    stream.addListener(listener);
    try {
      await ready.future.timeout(const Duration(seconds: 30));
      return () => stream.removeListener(listener);
    } catch (_) {
      stream.removeListener(listener);
      rethrow;
    }
  }

  /// Returns a screenshot of the page at [pageIndex] in [coreInfo].
  ///
  /// Note that screenshots do not include most* strokes
  /// because they're added separately to the PDF as vector graphics.
  /// See [shouldRasterizeStroke] for more details, or set
  /// [rasterizeAllStrokes] to true to include all strokes in the screenshot.
  ///
  /// You must dispose this image when you're done.
  static Future<ui.Image> screenshotPage({
    required EditorCoreInfo coreInfo,
    required int pageIndex,
    bool rasterizeAllStrokes = false,
    Size? targetSize,
    double? cropHeight,
    double pixelRatio = 2,
    bool requireAllImages = false,
  }) async {
    final page = coreInfo.pages[pageIndex].cloneForRasterization(
      rasterizeAllStrokes: rasterizeAllStrokes,
    );

    final imagesToLoad = [
      ?page.backgroundImage,
      ...page.images,
    ].where((image) => !image.loadedIn).toList(growable: false);
    final releaseImages = <VoidCallback>[];
    final rasterImages = <EditorImage>[];
    try {
      final loading = Future.wait(imagesToLoad.map((image) => image.loadIn()));
      if (requireAllImages) {
        await loading.timeout(const Duration(seconds: 30));
      } else {
        await loading.timeout(
          const Duration(seconds: 10),
          onTimeout: () => const [],
        );
      }
      if (requireAllImages) {
        Future<EditorImage> prepare(EditorImage image) async {
          final EditorImage ready = switch (image) {
            final PdfEditorImage pdf => await pdf.exportRaster(),
            final SvgEditorImage svg => await svg.exportRaster(),
            _ => image,
          };
          if (!identical(ready, image)) {
            rasterImages.add(ready);
            await ready.loadIn();
          }
          if (ready is PngEditorImage && ready.imageProvider != null) {
            releaseImages.add(await _holdDecodedImage(ready.imageProvider!));
          }
          return ready;
        }

        if (page.backgroundImage != null)
          page.backgroundImage = await prepare(page.backgroundImage!);
        for (var i = 0; i < page.images.length; i++) {
          page.images[i] = await prepare(page.images[i]);
        }
      }
      targetSize ??= page.size;
      coreInfo = coreInfo.copyWith(
        pages: [for (var i = 0; i < coreInfo.pages.length; ++i) page],
      );
      return await ScreenshotController.widgetToUiImage(
        EditorExporterTheme(
          targetSize: targetSize,
          child: CanvasPreview(
            pageIndex: pageIndex,
            height: cropHeight,
            coreInfo: coreInfo,
          ),
        ),
        pixelRatio: pixelRatio,
        targetSize: targetSize,
        delay: isThisATest ? .zero : const Duration(milliseconds: 200),
      );
    } finally {
      for (final release in releaseImages) release();
      for (final image in rasterImages) {
        image.dispose();
      }
      for (final image in imagesToLoad) unawaited(image.loadOut());
      page.disposeClonedData();
    }
  }
}

/// Applies a consistent theme to its [child] so that exports
/// look the same regardless of the user's current theme or device.
class EditorExporterTheme extends StatelessWidget {
  const new({super.key, required this.targetSize, required this.child});

  final Size targetSize;
  final Widget child;

  static final theme = ThemeData(
    brightness: .light,
    colorScheme: const ColorScheme.light(
      primary: Colors.blue,
      secondary: Colors.red,
    ),
  );

  @override
  Widget build(BuildContext context) {
    return MediaQuery(
      data: MediaQueryData(size: targetSize),
      child: Localizations(
        // needed to avoid errors with Quill, but not actually used
        locale: const Locale('en', 'US'),
        delegates: GlobalMaterialLocalizations.delegates,
        child: Theme(
          data: theme,
          child: DefaultTextStyle(
            style: theme.textTheme.bodyMedium!,
            child: SizedBox(
              width: targetSize.width,
              height: targetSize.height,
              child: FittedBox(
                fit: BoxFit.cover,
                alignment: Alignment.topLeft,
                child: child,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
