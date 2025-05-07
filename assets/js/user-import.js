jQuery(document).ready(($) => {
  // File selection handling
  $("#select-file-btn").on("click", () => {
    $("#import-file").click()
  })

  $("#import-file").on("change", function () {
    const file = this.files[0]
    const mediaUrl = userImportData.uploads_url;
    const now = new Date();
	const year = now.getFullYear();
	const month = String(now.getMonth() + 1).padStart(2, '0');
	const yearMonth = `${year}/${month}`; // Returns '2025/05'

    if (file) {
      $("#file-title").text(file.name)
      $("#file-size").text(formatFileSize(file.size))
      $("#file-url").text(mediaUrl+'/'+yearMonth+'/'+file.name)
      $("#file-details").show()
      $("#import-btn").show()
    } else {
      $("#file-details").hide()
      $("#import-btn").hide()
    }
  })

  // Import form submission
  $("#user-import-form").on("submit", function (e) {
    e.preventDefault()

    const formData = new FormData(this)
    formData.append("action", "process_import_batch")
    formData.append("nonce", userImportData.nonce)

    // Hide import form and show progress
    $(this).closest("#file-upload-container").hide()
    $("#import-progress-container").show()

    // Start import process
    $.ajax({
      url: userImportData.ajax_url,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: (response) => {
        if (response.success) {
          const data = response.data

          $("#file-info").text(data.file_name)
          $("#processed-count").text("0/" + data.total_rows)

          // Start processing batches
          processBatch(data.import_id, 0, data.total_rows)
        } else {
          showError(response.data)
          resetImportForm()
        }
      },
      error: () => {
        showError("An error occurred while starting the import.")
        resetImportForm()
      },
    })
  })

  function processBatch(importId, offset, totalRows) {
    $.ajax({
      url: userImportData.ajax_url,
      type: "POST",
      data: {
        action: "process_import_batch",
        nonce: userImportData.nonce,
        import_id: importId,
        offset: offset,
      },
      success: (response) => {
        if (response.success) {
          const data = response.data

          // Update progress
          updateProgress(data.processed, totalRows, data.percentage)

          if (data.status === "completed") {
            // Import completed
            setTimeout(() => {
              alert("Import completed successfully!")
              window.location.reload()
            }, 1000)
          } else {
            // Process next batch
            processBatch(importId, data.processed, totalRows)
          }
        } else {
          showError(response.data)
          resetImportForm()
        }
      },
      error: () => {
        showError("An error occurred while processing the batch.")
        resetImportForm()
      },
    })
  }

  function updateProgress(processed, total, percentage) {
    $("#percentage-complete").text(percentage + "%")
    $("#processed-count").text(processed + "/" + total)
    $(".progress-bar").css("width", percentage + "%")
  }

  function resetImportForm() {
    $("#import-progress-container").hide()
    $("#file-upload-container").show()
    $("#file-details").hide()
    $("#import-btn").hide()
    $("#user-import-form")[0].reset()
  }

  function showError(message) {
    alert("Error: " + message)
  }

  function formatFileSize(bytes) {
    if (bytes === 0) return "0 Bytes"

    const k = 1024
    const sizes = ["Bytes", "KB", "MB", "GB", "TB"]
    const i = Math.floor(Math.log(bytes) / Math.log(k))

    return Number.parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i]
  }

  // For the progress polling (used when user refreshes page during import)
  if ($("#import-progress-container").is(":visible")) {
    const importId = $("#import-progress-container").data("import-id")

    if (importId) {
      pollImportProgress(importId)
    }
  }

  function pollImportProgress(importId) {
    $.ajax({
      url: userImportData.ajax_url,
      type: "POST",
      data: {
        action: "get_import_progress",
        nonce: userImportData.nonce,
        import_id: importId,
      },
      success: (response) => {
        if (response.success) {
          const data = response.data

          // Update progress
          updateProgress(data.processed, data.total_rows, data.percentage)

          if (data.status === "completed") {
            // Import completed
            setTimeout(() => {
              alert("Import completed successfully!")
              window.location.reload()
            }, 1000)
          } else {
            // Continue polling
            setTimeout(() => {
              pollImportProgress(importId)
            }, 2000)
          }
        }
      },
      error: () => {
        setTimeout(() => {
          pollImportProgress(importId)
        }, 5000)
      },
    })
  }
})
