# kotlinx.serialization genera serializadores estaticos que R8 no ve usados.
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keepclassmembers class kotlinx.serialization.json.** {
    *** Companion;
}
-keepclasseswithmembers class kotlinx.serialization.json.** {
    kotlinx.serialization.KSerializer serializer(...);
}
-keep,includedescriptorclasses class app.nexus.**$$serializer { *; }
-keepclassmembers class app.nexus.** {
    *** Companion;
}
-keepclasseswithmembers class app.nexus.** {
    kotlinx.serialization.KSerializer serializer(...);
}

# libVLC llama a estas clases desde JNI.
-keep class org.videolan.libvlc.** { *; }
-keep class org.videolan.medialibrary.** { *; }

# Retrofit
-keepattributes Signature, Exceptions
-if interface * { @retrofit2.http.* public *** *(...); }
-keep,allowoptimization,allowshrinking,allowobfuscation class <3>
