#!/bin/bash

csv_file=$1
directoryPath=$2
pdf_url_row=$3

if [ -z "$pdf_url_row" ]; then
    #Row where the PDF url is. if not specified, default to 2
    pdf_url_row=2
fi

# Check if the CSV file is provided
if [ -z "$csv_file" ]; then
    echo "CSV not provided: $csv_file. Usage: ./download-files.sh <csv_file> <directory slug>"
    exit 1
fi

# Check if the directory is provided
if [ -z "$directoryPath" ]; then
    echo "Directory not provided: $directoryPath. Usage: ./download-files.sh <csv_file> <directory slug>"
    exit 1
fi

# Check if the CSV file exists
if [ ! -f "$csv_file" ]; then
    echo "File not found: $csv_file"
    exit 1
fi

# Check if the directory exists. if not, create it
if [ ! -d "$directoryPath" ]; then
    mkdir -p "$output_dir"
fi

# Directory to save downloaded files
output_dir=$directoryPath

# Read the first line to get headers
read -r header_line < "$csv_file"

# Convert headers to an array (preserves spaces)
IFS=',' read -r -a headers <<< "$header_line"

# Read the rest of the file line by line
while IFS=',' read -r -a row || [[ -n "$row" ]]; do
    # Skip the header row
    file_name=$(basename "${row[pdf_url_row]}")
    file_path="$output_dir/$file_name"
    
    # Check if the file already exists
    if [ -f "$file_path" ]; then
        echo "File already exists: $file_path"
    else
        echo "Downloading $url"
        wget "${row[pdf_url_row]}" --directory-prefix="$output_dir" --tries=1 --timeout=20 --no-check-certificate  --user-agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.159 Safari/537.36"
    fi
done < "$csv_file"

echo "Removing spaces in file names..."
for file in ${output_dir}/*; do
    new_file=$(echo "$file" | tr ' ' '_')
    if [[ "$new_file" != *.pdf ]]; then
        new_file="${new_file}.pdf"
    fi
    mv "$file" "$new_file"
done

echo "Pass PDF accesibility checker"
# Loop through all the PDF files in the directory
for file in "$directoryPath"/*.pdf; do
    # Check if there are no PDF files
    if [ ! -e "$file" ]; then
        echo "No PDF files found in the directory."
        exit 1
    fi

    # Full path of the PDF file
    filePath=$file;
    echo "Processing file: $filePath"

    # Run the process-pdfs.js script with the file as a parameter
    node pdf-accessibility-checker.js "$filePath"
    if [ $? -ne 0 ]; then
        echo "Error processing file $filePath"
    fi
done

echo "done";