package com.nativephp.mobile.network

import android.app.AlertDialog
import android.app.Activity
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Environment
import android.provider.MediaStore
import android.webkit.CookieManager
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.FileProvider
import androidx.lifecycle.DefaultLifecycleObserver
import androidx.lifecycle.LifecycleOwner
import java.io.File
import java.util.UUID

/** Android integration owned by Kardex: file/camera selection and authenticated PDFs. */
class KardexAttachments(private val activity: ComponentActivity) {
    private var callback: ValueCallback<Array<Uri>>? = null
    private var capture: Uri? = null
    private val downloads = activity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
    private val pending = mutableSetOf<Long>()
    private val picker = activity.activityResultRegistry.register(
        "kardex-attachments-${UUID.randomUUID()}", ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val selected = if (result.resultCode == Activity.RESULT_OK) {
            val data = result.data
            val fromGallery = buildList {
                data?.clipData?.let { clip ->
                    for (index in 0 until clip.itemCount) {
                        clip.getItemAt(index).uri?.let { add(it) }
                    }
                }
                data?.data?.let { add(it) }
            }
            fromGallery.ifEmpty { listOfNotNull(capture) }
        } else emptyList()
        val safe = selected.filter { uri ->
            uri.scheme == "content" && (uri.authority != "${activity.packageName}.fileprovider" || uri == capture)
        }.distinct().toTypedArray().takeIf { it.isNotEmpty() }
        if (result.resultCode == Activity.RESULT_OK && safe == null) {
            toast("No se pudo abrir la imagen seleccionada. Elige otra foto e intenta nuevamente.")
        }
        callback?.onReceiveValue(safe)
        callback = null
        capture = null
    }
    private val completed = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1)
            if (!pending.remove(id)) return
            val file = downloads.getUriForDownloadedFile(id)
            if (file == null) { toast("No se pudo descargar el PDF. Revisa la conexión."); return }
            try {
                activity.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(file, "application/pdf").addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION))
            } catch (_: ActivityNotFoundException) {
                toast("PDF guardado en Descargas.")
            }
        }
    }
    init {
        activity.registerReceiver(completed, IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE), Context.RECEIVER_EXPORTED)
        activity.lifecycle.addObserver(object : DefaultLifecycleObserver {
            override fun onDestroy(owner: LifecycleOwner) {
                callback?.onReceiveValue(null)
                callback = null
                picker.unregister()
                runCatching { activity.unregisterReceiver(completed) }
            }
        })
    }
    private fun toast(text: String) { Toast.makeText(activity, text, Toast.LENGTH_LONG).show() }

    fun choose(params: WebChromeClient.FileChooserParams, result: ValueCallback<Array<Uri>>): Boolean {
        callback?.onReceiveValue(null)
        callback = result
        capture = null
        if (!params.isCaptureEnabled && params.acceptTypes.any { it.startsWith("image/") }) {
            AlertDialog.Builder(activity).setTitle("Agregar fotos")
                .setItems(arrayOf("Tomar foto", "Elegir del celular")) { _, choice -> launchSelection(params, choice == 0) }
                .setOnCancelListener { callback?.onReceiveValue(null); callback = null }
                .show()
        } else { launchSelection(params, params.isCaptureEnabled) }
        return true
    }
    private fun launchSelection(params: WebChromeClient.FileChooserParams, camera: Boolean) {
        try {
            if (camera) {
                val directory = File(activity.cacheDir, "kardex-camera").apply { mkdirs() }
                val file = File.createTempFile("foto-", ".jpg", directory)
                capture = FileProvider.getUriForFile(activity, "${activity.packageName}.fileprovider", file)
                val intent = Intent(MediaStore.ACTION_IMAGE_CAPTURE).putExtra(MediaStore.EXTRA_OUTPUT, capture)
                    .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
                picker.launch(intent)
            } else {
                picker.launch(params.createIntent().apply {
                    putExtra(Intent.EXTRA_ALLOW_MULTIPLE, params.mode == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE)
                    addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                })
            }
        } catch (_: Exception) {
            callback?.onReceiveValue(null)
            callback = null
            capture = null
            toast("No se pudo abrir la cámara o el selector de archivos.")
        }
    }
    fun download(url: String, userAgent: String?, disposition: String?, mime: String?) {
        try {
            val filename = "Kardex-${System.currentTimeMillis()}.pdf"
            val request = DownloadManager.Request(Uri.parse(url))
                .setTitle(filename).setMimeType(mime ?: "application/pdf")
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, filename)
            CookieManager.getInstance().getCookie(url)?.let { request.addRequestHeader("Cookie", it) }
            userAgent?.let { request.addRequestHeader("User-Agent", it) }
            pending.add(downloads.enqueue(request))
            toast("Descargando PDF…")
        } catch (_: Exception) { toast("No se pudo iniciar la descarga.") }
    }
}